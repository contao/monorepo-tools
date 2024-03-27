<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools;

use Contao\MonorepoTools\Git\Commit;
use Contao\MonorepoTools\Git\Repository;
use Contao\MonorepoTools\Git\Tree;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

class Splitter
{
    private readonly string $objectsCachePath;

    private Repository $repository;

    /**
     * @var array<string, Commit>
     */
    private array $commitCache = [];

    /**
     * @var array array<string, Tree>
     */
    private array $treeCache = [];

    public function __construct(
        private readonly string $monorepoUrl,
        private readonly string $branchFilter,
        private readonly array $repoUrlsByFolder,
        private readonly string $cacheDir,
        private readonly bool $forcePush,
        private readonly string|null $branchOrTag,
        private readonly OutputInterface $output,
    ) {
        $this->objectsCachePath = $cacheDir.'/objects-v1.cache';

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', $cacheDir));
        }
    }

    public function split(): void
    {
        if (file_exists($this->objectsCachePath)) {
            $this->output->writeln("\nLoad data from cache...");

            [$this->commitCache, $this->treeCache] = unserialize(
                file_get_contents($this->objectsCachePath),
                [Commit::class, Tree::class],
            );
        }

        $this->output->writeln("\nLoad monorepo...");

        $this->repository = new Repository($this->cacheDir.'/repo.git', $this->output);

        if (is_dir($this->cacheDir.'/repo.git')) {
            $this->repository->removeBranches()->removeTags();
        } else {
            $this->repository->init();
        }

        $this->repository
            ->setConfig('gc.auto', '0')
            ->addRemote('mono', $this->monorepoUrl)
            ->fetch('mono')
        ;

        foreach ($this->repoUrlsByFolder as $subFolder => $config) {
            $this->repository->addRemote($subFolder, $config['url']);
        }

        $this->repository->fetchConcurrent(array_keys($this->repoUrlsByFolder));

        foreach ($this->repoUrlsByFolder as $subFolder => $config) {
            foreach ($config['mapping'] as $monoHash => $splitHash) {
                $monoTreeHash = $this
                    ->getTreeObject($this->getCommitObject($monoHash)->getTreeHash())
                    ->getSubtreeHash($subFolder)
                ;

                $splitTreeHash = $this->getCommitObject($splitHash)->getTreeHash();

                if ($monoTreeHash !== $splitTreeHash) {
                    throw new \RuntimeException(sprintf('Invalid mapping from %s to %s. Tree for folder %s does not match.', $monoHash, $splitHash, $subFolder));
                }
            }
        }

        $branchCommits = $this->repository->getRemoteBranches('mono');
        $tagCommits = [];

        if (null !== $this->branchOrTag) {
            if (isset($branchCommits[$this->branchOrTag])) {
                if (!preg_match($this->branchFilter, $this->branchOrTag)) {
                    $this->output->writeln("\nBranch $this->branchOrTag does not match the branch filter $this->branchFilter.");

                    return;
                }

                // Only use the specified branch
                $branchCommits = [$this->branchOrTag => $branchCommits[$this->branchOrTag]];
            } else {
                try {
                    $tagHash = $this->repository
                        ->fetchTag($this->branchOrTag, 'mono', 'remote/mono/')
                        ->getTag('remote/mono/'.$this->branchOrTag)
                    ;
                } catch (\Exception) {
                    throw new InvalidArgumentException(sprintf('Branch or tag %s does not exist, use one of %s', $this->branchOrTag, implode(', ', array_keys($branchCommits))));
                }

                $tagCommits = [$this->branchOrTag => $tagHash];
                $branchCommits = [];
            }
        } else {
            foreach (array_keys($branchCommits) as $branch) {
                if (!preg_match($this->branchFilter, $branch)) {
                    unset($branchCommits[$branch]);
                }
            }

            if ([] === $branchCommits) {
                throw new \RuntimeException(sprintf('No branch matching the filter %s found.', $this->branchFilter));
            }
        }

        $this->output->writeln("\nRead commits...");

        $commitObjects = $this->readCommits([...array_values($branchCommits), ...array_values($tagCommits)]);

        if ([] === $commitObjects) {
            throw new \RuntimeException(sprintf('No commits found for: %s %s', print_r($branchCommits, true), print_r($tagCommits, true)));
        }

        $this->output->writeln("\nSplit commits...");

        $hashMapping = $this->splitCommits($commitObjects, $this->repoUrlsByFolder);

        if ([] === $hashMapping) {
            throw new \RuntimeException(sprintf('No hash mapping for commits: %s', print_r($commitObjects, true)));
        }

        $pushBranches = [];
        $pushTags = [];

        if ([] !== $branchCommits) {
            $this->output->writeln("\nCreate branches...");

            foreach ($branchCommits as $branch => $commit) {
                foreach (array_keys($this->repoUrlsByFolder) as $subRepo) {
                    if (isset($hashMapping[$subRepo][$commit])) {
                        $this->repository->addBranch($subRepo.'/'.$branch, $hashMapping[$subRepo][$commit]);
                        $pushBranches[] = [$subRepo.'/'.$branch, $subRepo, $branch];
                    }
                }
            }
        }

        if ([] !== $tagCommits) {
            $this->output->writeln("\nCreate tags...");

            foreach ($tagCommits as $tag => $commit) {
                foreach (array_keys($this->repoUrlsByFolder) as $subRepo) {
                    if (isset($hashMapping[$subRepo][$commit])) {
                        $this->repository->addTag('remote/'.$subRepo.'/'.$tag, $hashMapping[$subRepo][$commit]);
                        $pushTags[] = ['remote/'.$subRepo.'/'.$tag, $subRepo, $tag];
                    }
                }
            }
        }

        $this->output->writeln("\nUpdate cache...");

        file_put_contents($this->objectsCachePath, serialize([$this->commitCache, $this->treeCache]));

        $this->output->writeln("\nPush to remotes...");

        $this->repository->pushBranches($pushBranches, $this->forcePush);
        $this->repository->pushTags($pushTags);

        $this->output->writeln("\nDone 🎉");
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function splitCommits(array $commitObjects, array $subRepos): array
    {
        $hashMapping = [];
        $emptyCommits = [];

        foreach ($subRepos as $subRepo => $config) {
            $hashMapping[$subRepo] = $config['mapping'];
        }

        $pending = array_keys($commitObjects);

        while (\count($pending)) {
            $current = array_pop($pending);

            if (isset($emptyCommits[$current])) {
                continue;
            }

            foreach (array_keys($subRepos) as $subRepo) {
                if (isset($hashMapping[$subRepo][$current])) {
                    continue 2;
                }
            }

            $missingParents = [];

            foreach ($commitObjects[$current]->getParentHashes() as $parent) {
                if (isset($emptyCommits[$parent])) {
                    continue;
                }

                foreach (array_keys($subRepos) as $subRepo) {
                    if (isset($hashMapping[$subRepo][$parent])) {
                        continue 2;
                    }
                }

                $missingParents[] = $parent;
            }

            if ([] !== $missingParents) {
                $pending[] = $current;

                foreach ($missingParents as $parent) {
                    $pending[] = $parent;
                }

                continue;
            }

            $commitIsEmpty = !$this->splitCommit($current, $commitObjects[$current]->getTreeHash(), $hashMapping, $subRepos);

            if ($commitIsEmpty) {
                $emptyCommits[$current] = true;
            }
        }

        return $hashMapping;
    }

    /**
     * @return bool True if at least one split commit was created successfully
     */
    private function splitCommit($commitHash, $treeHash, &$hashMapping, array $subRepos): bool
    {
        $treeObject = $this->getTreeObject($treeHash);
        $success = false;

        foreach (array_keys($subRepos) as $subRepo) {
            $subTreeHash = $treeObject->getSubtreeHash($subRepo);

            if (!$subTreeHash) {
                if ('4b825dc642cb6eb9a060e54bf8d69288fbee4904' === $treeHash) {
                    $subTreeHash = $treeHash;
                } else {
                    continue;
                }
            }

            $hashMapping[$subRepo][$commitHash] = $this->createNewCommit($commitHash, $subTreeHash, $hashMapping[$subRepo]);
            $success = true;
        }

        return $success;
    }

    private function createNewCommit(string $commitHash, string $treeHash, &$hashMapping): string
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

    /**
     * @return array<string, Commit>
     */
    private function readCommits(array $baseCommits): array
    {
        $commits = [];
        $pending = $baseCommits;

        while (\count($pending)) {
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

            foreach ($commits[$current]->getParentHashes() as $parent) {
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
