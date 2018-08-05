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
    const VERSION_MAPPING = [
        '0-RC5' => '0-RC4',
        '0-RC4' => '0-RC3',
        '0-RC3' => '0-RC2',
        '0-RC2' => '0-RC1',
        '0-RC1' => '0-beta5',
        '0-beta5' => '0-beta4',
        '0-beta4' => '0-beta3',
        '0-beta3' => '0-beta2',
        '0-beta2' => '0-beta1',
        '0-beta1' => '0',
    ];

    private $monorepoUrl;
    private $repoUrlsByFolder;

    /**
     * @var string[]
     */
    private $ignoreCommits;

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

    /**
     * @var array<string,array<string,string>>
     */
    private $exportMappingByFolder = [];

    public function __construct(string $monorepoUrl, array $repoUrlsByFolder, array $ignoreCommits, string $cacheDir, OutputInterface $output)
    {
        $this->monorepoUrl = $monorepoUrl;
        $this->repoUrlsByFolder = $repoUrlsByFolder;
        $this->ignoreCommits = $ignoreCommits;
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

        $this->output->writeln("\nLoad repositories...");

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

        $this->output->writeln("\nMerge repositories...");
        $mainCommits = [];
        foreach($this->repoUrlsByFolder as $subFolder => $remote) {
            $mainCommits[$subFolder] = $this->mergeRepo($subFolder);
        }

        $this->output->writeln("\nCreate branches and tags...");

        $trees = [];
        foreach($mainCommits as $subFolder => $commits) {
            foreach ($commits['branches'] as $branch => $commit) {
                $trees['branches'][$branch][$subFolder] = $this->getCommitObject($commit)->getTreeHash();
            }
            foreach ($commits['tags'] as $tag => $commit) {
                $trees['tags'][$tag][$subFolder] = $this->getCommitObject($commit)->getTreeHash();
            }
        }

        foreach ($trees['branches'] as $branch => $treeByFolder) {
            $this->repository->addBranch(
                $branch,
                $this->repository->commitTree(
                    $this->combineTrees($treeByFolder),
                    'MERGE 🎉',
                    array_filter(array_map(
                        function($commits) use($branch) {
                            return $commits['branches'][$branch] ?? null;
                        },
                        $mainCommits
                    ))
                )
            );
        }

        foreach ($trees['tags'] as $tag => $treeByFolder) {
            $this->repository->addTag(
                $tag,
                $this->repository->commitTree(
                    $this->combineTrees($treeByFolder),
                    'Monorepo Version '.$tag,
                    array_filter(array_map(
                        function($commits, $subFolder) use($tag) {
                            while (!isset($commits['tags'][$tag])) {
                                $parts = explode('.', $tag);
                                if (isset($parts[2]) && is_numeric($parts[2]) && (int) $parts[2] > 0) {
                                    $parts[2]--;
                                    $tag = implode('.', $parts);
                                }
                                elseif (isset($parts[2]) && isset(self::VERSION_MAPPING[$parts[2]])) {
                                    $parts[2] = self::VERSION_MAPPING[$parts[2]];
                                    $tag = implode('.', $parts);
                                }
                                else {
                                    $this->output->writeln("\e[41m\n\n  Missing $tag in $subFolder\n\e[0m");
                                    return null;
                                }
                            }
                            return $commits['tags'][$tag];
                        },
                        $mainCommits,
                        array_keys($mainCommits)
                    )),
                    true
                )
            );
        }

        $this->output->writeln("\nDone 🎉");
        $this->output->writeln('Use this mapping for the split configuration:');
        $this->output->writeln(var_export($this->exportMappingByFolder, true));
    }

    private function mergeRepo($subFolder)
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
        }

        $return = [];
        foreach ($branchCommits as $branch => $commit) {
            $return['branches'][$branch] = $hashMapping[$commit];
            $this->exportMappingByFolder[$subFolder][$hashMapping[$commit]] = $commit;
        }

        foreach ($this->repository->getTags('remote/'.$subFolder.'/') as $tag => $commitHash) {
            if (!isset($hashMapping[$commitHash])) {
                throw new \RuntimeException(sprintf('Missing Commit hash %s for tag %s. %s', $commitHash, $tag, print_r($hashMapping, true)));
            }
            $return['tags'][$tag] = $hashMapping[$commitHash];
            $this->repository->removeTag('remote/'.$subFolder.'/'.$tag);
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
                if (!isset($hashMapping[$parent]) && !\in_array($parent, $this->ignoreCommits, true)) {
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
        // Check for empty sub tree
        if ($subTree === '4b825dc642cb6eb9a060e54bf8d69288fbee4904') {
            return $subTree;
        }

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
            array_filter(array_map(function($parentHash) use (&$hashMapping) {
                return \in_array($parentHash, $this->ignoreCommits, true)
                    ? null
                    : $hashMapping[$parentHash]
                ;
            }, $oldCommit->getParentHashes()))
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
                if (!\in_array($parent, $this->ignoreCommits, true)) {
                    $pending[] = $parent;
                }
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
