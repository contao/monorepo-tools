<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo;

use Contao\Monorepo\Git\Commit;
use Contao\Monorepo\Git\Repository;
use Contao\Monorepo\Git\Tree;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Merger
{
    private $monorepoUrl;
    private $repoUrlsByFolder;

    /**
     * @var Repository
     */
    private $repository;
    private $cacheDir;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var array<string,Commit>
     */
    private $commitCache = [];

    /**
     * @var array<string,Tree>
     */
    private $treeCache = [];

    public function __construct(string $monorepoUrl, array $repoUrlsByFolder, string $cacheDir, OutputInterface $output)
    {
        $this->monorepoUrl = $monorepoUrl;
        $this->repoUrlsByFolder = $repoUrlsByFolder;
        $this->cacheDir = $cacheDir;
        $this->output = $output;

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', $cacheDir));
        }
    }

    public function merge()
    {
        (new Filesystem())->remove($this->cacheDir.'/repo');
        (new Filesystem())->mkdir($this->cacheDir.'/repo');

        $this->output->writeln('Load repositories...');

        $this->repository = new Repository($this->cacheDir.'/repo', $this->output);
        $this->repository
            ->init()
        ;

        foreach($this->repoUrlsByFolder as $subFolder => $remote) {
            $this->repository
                ->addRemote($subFolder, $remote)
                ->fetch($subFolder)
                ->fetchTags($subFolder, 'remote/'.$subFolder.'/')
            ;
        }

        $mainCommits = [];
        foreach($this->repoUrlsByFolder as $subFolder => $remote) {
            $mainCommits[$subFolder] = $this->mergeRepo($subFolder, $remote);
        }

        $trees = [];
        foreach($mainCommits as $subFolder => $commits) {
            foreach ($commits as $branch => $commit) {
                $trees[$branch][$subFolder] = $this->getCommitObject($commit)->getTreeHash();
            }
        }

        foreach ($trees as $branch => $treeByFolder) {
            $this->repository->addBranch(
                $branch,
                $this->repository->commitTree(
                    $this->combineTrees($treeByFolder),
                    'MERGE 🎉',
                    array_filter(array_map(
                        function($commits) use($branch) {
                            return $commits[$branch] ?? null;
                        },
                        $mainCommits
                    ))
                )
            );
        }
    }

    private function mergeRepo($subFolder, $remote)
    {
        $branchCommits = $this->repository->getRemoteBranches($subFolder);

        $commits = $this->readCommits(array_values($branchCommits));

        if (empty($commits)) {
            throw new \RuntimeException(sprintf('No commits found for: %s', print_r($branchCommits, true)));
        }

        $hashMapping = $this->moveCommitsToSubfolder($commits, $subFolder);

        if (empty($hashMapping)) {
            throw new \RuntimeException(sprintf('No hash mapping for commits: %s', print_r($commits, true)));
        }

        foreach ($this->repository->getTags('remote/'.$subFolder.'/') as $tag => $commitHash) {
            $newTag = $subFolder.'-'.$tag;
            if (!isset($hashMapping[$commitHash])) {
                throw new \RuntimeException(sprintf('Missing Commit hash %s for tag %s. %s', $commitHash, $tag, print_r($hashMapping, true)));
            }
            $this->repository->addTag($newTag, $hashMapping[$commitHash]);
            $this->repository->removeTag('remote/'.$subFolder.'/'.$tag);
        }

        $return = [];
        foreach ($branchCommits as $branch => $commit) {
            $return[$branch] = $hashMapping[$commit];
        }

        $this->repository->removeRemote($subFolder);

        return $return;
    }

    /**
     * @param array<string,Commit> $commits
     *
     * @return array<string,string>
     */
    private function moveCommitsToSubfolder(array $commits, string $subFolder)
    {
        $hashMapping = [];
        $pending = array_keys($commits);
        while(count($pending)) {
            $current = array_pop($pending);
            if (isset($hashMapping[$current])) {
                continue;
            }
            $missingParents = [];
            foreach ($commits[$current]->getParentHashes() as $parent) {
                if (!isset($hashMapping[$parent])) {
                    $missingParents[] = $parent;
                }
            }
            if (count($missingParents)) {
                $pending[] = $current;
                foreach ($missingParents as $parent) {
                    $pending[] = $parent;
                }
                continue;
            }
            $hashMapping[$current] = $this->moveCommitToSubfolder($current, $commits[$current]->getTreeHash(), $hashMapping, $subFolder);
        }
        return $hashMapping;
    }

    private function moveCommitToSubfolder($commit, $tree, &$hashMapping, string $subFolder)
    {
        $newTree = $this->createTree($subFolder, $tree);
        return $this->createNewCommit($commit, $newTree, $hashMapping);
    }

    private function createTree(string $subFolder, string $subTree)
    {
        $tree = new Tree("40000 $subFolder\0" . hex2bin($subTree));
        $this->repository->addObject($tree);

        return $tree->getHash();
    }

    private function combineTrees(array $trees)
    {
        ksort($trees);

        $tree = Tree::createFromTrees(array_values(array_map([$this->repository, 'getTree'], $trees)));

        $this->repository->addObject($tree);

        return $tree->getHash();
    }

    private function createNewCommit(string $commitHash, string $treeHash, array &$hashMapping): string
    {
        $oldCommit = $this->getCommitObject($commitHash);

        $newCommit = $oldCommit->withNewTreeAndParents(
            $treeHash,
            array_map(function($parentHash) use (&$hashMapping) {
                return $hashMapping[$parentHash];
            }, $oldCommit->getParentHashes())
        );

        $this->repository->addObject($newCommit);

        $newHash = $newCommit->getHash();

        $this->commitCache[$newHash] = $newCommit;

        return $newHash;
    }

    private function readCommits(array $baseCommits): array
    {
        $commits = [];
        $pending = $baseCommits;

        while(count($pending)) {
            $current = array_shift($pending);
            if (isset($commits[$current])) {
                continue;
            }
            $commits[$current] = $this->getCommitObject($current);
            foreach($commits[$current]->getParentHashes() as $parent) {
                $pending[] = $parent;
            }
        }

        return $commits;
    }

    private function getCommitObject($hash)
    {
        if (isset($this->commitCache[$hash])) {
            return $this->commitCache[$hash];
        }

        $commit = $this->repository->getCommit($hash);

        $this->commitCache[$hash] = $commit;

        return $commit;
    }
}
