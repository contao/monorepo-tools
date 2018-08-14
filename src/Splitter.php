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

class Splitter
{
    private $monorepoUrl;
    private $repoUrlsByFolder;
    private $cacheDir;
    private $forcePush;
    private $objectsCachePath;

    /**
     * @var Repository
     */
    private $repository;

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

    public function __construct(string $monorepoUrl, array $repoUrlsByFolder, string $cacheDir, bool $forcePush, OutputInterface $output)
    {
        $this->monorepoUrl = $monorepoUrl;
        $this->repoUrlsByFolder = $repoUrlsByFolder;
        $this->cacheDir = $cacheDir;
        $this->objectsCachePath = $cacheDir.'/objects-v1.cache';
        $this->forcePush = $forcePush;
        $this->output = $output;

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', $cacheDir));
        }
    }

    public function split()
    {
        if (file_exists($this->objectsCachePath)) {
            $this->output->writeln("\nLoad data from cache...");
            [$this->commitCache, $this->treeCache] = unserialize(
                file_get_contents($this->objectsCachePath),
                [Commit::class, Tree::class]
            );
        }

        (new Filesystem())->remove($this->cacheDir.'/repo');
        (new Filesystem())->mkdir($this->cacheDir.'/repo');

        $this->output->writeln("\nLoad monorepo...");

        $this->repository = new Repository($this->cacheDir.'/repo', $this->output);
        $this->repository
            ->init()
            ->addRemote('mono', $this->monorepoUrl)
            ->fetch('mono')
            ->fetchTags('mono', 'remote/mono/')
        ;

        foreach ($this->repoUrlsByFolder as $subFolder => $config) {
            $this->repository
                ->addRemote($subFolder, $config['url'])
                ->fetch($subFolder)
            ;
            foreach ($config['mapping'] as $monoHash => $splitHash) {
                $monoTreeHash = $this->getTreeObject(
                    $this->getCommitObject($monoHash)->getTreeHash()
                )->getSubtreeHash($subFolder);
                $splitTreeHash = $this->getCommitObject($splitHash)->getTreeHash();
                if ($monoTreeHash !== $splitTreeHash) {
                    throw new \RuntimeException(sprintf(
                        'Invalid mapping from %s to %s. Tree for folder %s does not match.',
                        $monoHash,
                        $splitHash,
                        $subFolder
                    ));
                }
            }
        }

        $branchCommits = $this->repository->getRemoteBranches('mono');

        $this->output->writeln("\nRead commits...");
        $commitObjects = $this->readCommits(array_values($branchCommits));

        if (empty($commitObjects)) {
            throw new \RuntimeException(sprintf('No commits found for: %s', print_r($branchCommits, true)));
        }

        $this->output->writeln("\nSplit commits...");
        $hashMapping = $this->splitCommits($commitObjects, $this->repoUrlsByFolder);

        if (empty($hashMapping)) {
            throw new \RuntimeException(sprintf('No hash mapping for commits: %s', print_r($commitObjects, true)));
        }

        $this->output->writeln("\nCreate branches...");
        $addedBranches = [];
        foreach ($branchCommits as $branch => $commit) {
            foreach ($this->repoUrlsByFolder as $subRepo => $config) {
                if (isset($hashMapping[$subRepo][$commit])) {
                    $this->repository->addBranch($subRepo.'/'.$branch, $hashMapping[$subRepo][$commit]);
                    $addedBranches[$subRepo][] = $branch;
                }
            }
        }

        $this->output->writeln("\nCreate tags...");
        $addedTags = [];
        foreach ($this->repository->getTags('remote/mono/') as $tag => $commit) {
            foreach ($this->repoUrlsByFolder as $subRepo => $config) {
                if (isset($hashMapping[$subRepo][$commit])) {
                    $this->repository->addTag('remote/'.$subRepo.'/'.$tag, $hashMapping[$subRepo][$commit]);
                    $addedTags[$subRepo][] = $tag;
                }
            }
        }

        $this->output->writeln("\nUpdate cache...");
        file_put_contents($this->objectsCachePath, serialize([$this->commitCache, $this->treeCache]));

        $this->output->writeln("\nPush to remotes...");

        foreach ($addedBranches as $subRepo => $branches) {
            foreach ($branches as $branch) {
                $this->repository->pushBranch($subRepo.'/'.$branch, $subRepo, $branch, $this->forcePush);
            }
        }

        foreach ($addedTags as $subRepo => $tags) {
            foreach ($tags as $tag) {
                $this->repository->pushTag('remote/'.$subRepo.'/'.$tag, $subRepo, $tag, $this->forcePush);
            }
        }

        $this->output->writeln("\nDone 🎉");
    }

    private function splitCommits(array $commitObjects, array $subRepos)
    {
        $hashMapping = [];
        foreach ($subRepos as $subRepo => $config) {
            $hashMapping[$subRepo] = $config['mapping'];
        }
        $pending = array_keys($commitObjects);
        while(count($pending)) {
            $current = array_pop($pending);
            foreach ($subRepos as $subRepo => $config) {
                if (isset($hashMapping[$subRepo][$current])) {
                    continue 2;
                }
            }
            $missingParents = [];
            foreach ($commitObjects[$current]->getParentHashes() as $parent) {
                foreach ($subRepos as $subRepo => $config) {
                    if (isset($hashMapping[$subRepo][$parent])) {
                        continue 2;
                    }
                }
                $missingParents[] = $parent;
            }
            if (count($missingParents)) {
                $pending[] = $current;
                foreach ($missingParents as $parent) {
                    $pending[] = $parent;
                }
                continue;
            }
            $this->splitCommit($current, $commitObjects[$current]->getTreeHash(), $hashMapping, $subRepos);
        }
        return $hashMapping;
    }

    private function splitCommit($commitHash, $treeHash, &$hashMapping, array $subRepos)
    {
        $newCommits = [];
        $treeObject = $this->getTreeObject($treeHash);
        $failure = true;
        foreach ($subRepos as $subRepo => $config) {
            $subTreeHash = $treeObject->getSubtreeHash($subRepo);
            if (!$subTreeHash) {
                if ($treeHash === '4b825dc642cb6eb9a060e54bf8d69288fbee4904') {
                    $subTreeHash = $treeHash;
                }
                else {
                    continue;
                }
            }
            $hashMapping[$subRepo][$commitHash] = $this->createNewCommit($commitHash, $subTreeHash, $hashMapping[$subRepo]);
            $failure = false;
        }
        if ($failure) {
            throw new \RuntimeException(sprintf('No subfolder found in commit %s. %s', $commitHash, print_r($treeObject, true)));
        }
    }

    private function createNewCommit(string $commitHash, string $treeHash, &$hashMapping)
    {
        $commit = $this->getCommitObject($commitHash);

        $newParents = [];
        foreach ($commit->getParentHashes() as $parent) {
            if (isset($hashMapping[$parent]) && !\in_array($hashMapping[$parent], $newParents, true)) {
                $newParents[] = $hashMapping[$parent];
            }
        }

        foreach ($newParents as $parentHash) {
            if ($this->getCommitObject($parentHash)->getTreeHash() === $treeHash) {
                return $parentHash;
            }
        }

        $newCommit = $commit->withTree($treeHash)->withParents($newParents);

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
            foreach ($this->repoUrlsByFolder as $config) {
                if (isset($config['mapping'][$current])) {
                    continue 2;
                }
            }
            foreach($commits[$current]->getParentHashes() as $parent) {
                $pending[] = $parent;
            }
        }

        return $commits;
    }

    private function getTreeObject($hash): Tree
    {
        if (isset($this->treeCache[$hash])) {
            return $this->treeCache[$hash];
        }

        $tree = $this->repository->getTree($hash);

        $this->treeCache[$hash] = $tree;

        return $tree;
    }

    private function getCommitObject($hash): Commit
    {
        if (isset($this->commitCache[$hash])) {
            return $this->commitCache[$hash];
        }

        $commit = $this->repository->getCommit($hash);

        $this->commitCache[$hash] = $commit;

        return $commit;
    }
}
