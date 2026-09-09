<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Git;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Repository
{
    public function __construct(
        private readonly string $path,
        private readonly OutputInterface $output,
    ) {
    }

    public function init(): self
    {
        $this->execute(['git', '--git-dir='.$this->path, 'init', '--bare', $this->path]);

        return $this;
    }

    public function setConfig(string $key, string $value): self
    {
        $this->execute(['git', '--git-dir='.$this->path, 'config', $key, $value]);

        return $this;
    }

    public function removeBranches(): self
    {
        (new Filesystem())->remove($this->path.'/refs/heads');

        return $this;
    }

    public function removeTags(): self
    {
        (new Filesystem())->remove($this->path.'/refs/tags');

        return $this;
    }

    public function addRemote(string $name, string $url): self
    {
        if (\in_array($name, $this->run(['git', '--git-dir='.$this->path, 'remote']), true)) {
            $this->execute(['git', '--git-dir='.$this->path, 'remote', 'set-url', $name, $url]);
        } else {
            $this->execute(['git', '--git-dir='.$this->path, 'remote', 'add', $name, $url]);
        }

        return $this;
    }

    public function removeRemote(string $name): self
    {
        $this->execute(['git', '--git-dir='.$this->path, 'remote', 'rm', $name]);

        return $this;
    }

    public function fetch(string $remote): self
    {
        $this->execute(['git', '--git-dir='.$this->path, 'fetch', '--no-tags', $remote]);

        return $this;
    }

    public function fetchConcurrent(array $remotes): self
    {
        $this->executeConcurrent(
            array_map(
                fn ($remote) => ['git', '--git-dir='.$this->path, 'fetch', '--no-tags', $remote],
                $remotes,
            ),
        );

        return $this;
    }

    public function fetchTags(string $remote, string $prefix): self
    {
        $this->execute([
            'git',
            '--git-dir='.$this->path,
            'fetch',
            '--no-tags',
            $remote,
            '+refs/tags/*:refs/tags/'.$prefix.'*',
        ]);

        return $this;
    }

    public function fetchTag(string $tag, string $remote, string $prefix): self
    {
        $this->execute([
            'git',
            '--git-dir='.$this->path,
            'fetch',
            '--no-tags',
            $remote,
            '+refs/tags/'.$tag.':refs/tags/'.$prefix.$tag,
        ]);

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getRemoteBranches(string $remote): array
    {
        $branches = [];

        foreach ($this->run(['git', '--git-dir='.$this->path, 'ls-remote', '--heads', $remote]) as $line) {
            [$commit, $branch] = explode("\t", trim($line)) + ['', ''];

            if (40 !== \strlen($commit) || !str_starts_with($branch, 'refs/heads/')) {
                continue;
            }

            $branch = substr($branch, \strlen('refs/heads/'));
            $branches[$branch] = $commit;
        }

        return $branches;
    }

    /**
     * @return array<string, string>
     */
    public function getTags(string $prefix): array
    {
        $tags = [];

        foreach ($this->run(['git', '--git-dir='.$this->path, 'tag', '-l', $prefix.'*']) as $tag) {
            if ('' === $tag) {
                continue;
            }

            $tag = substr(trim($tag), \strlen($prefix));
            $tags[$tag] = $this->run(['git', '--git-dir='.$this->path, 'rev-list', '-n', '1', $prefix.$tag])[0];
        }

        return $tags;
    }

    public function getTag(string $tag): string
    {
        $result = $this->run(['git', '--git-dir='.$this->path, 'rev-list', '-n', '1', $tag]);

        if ([] === $result || 40 !== \strlen($result[0])) {
            throw new \RuntimeException(\sprintf('Tag %s not found.', $tag));
        }

        return $result[0];
    }

    public function addTag(string $name, string $hash): self
    {
        $this->execute(['git', '--git-dir='.$this->path, 'tag', $name, $hash]);

        return $this;
    }

    public function removeTag(string $name): self
    {
        $this->execute(['git', '--git-dir='.$this->path, 'tag', '-d', $name]);

        return $this;
    }

    public function getCommit(string $hash): Commit
    {
        return new Commit(implode("\n", $this->run(['git', '--git-dir='.$this->path, 'cat-file', 'commit', $hash])));
    }

    public function getTree(string $hash): Tree
    {
        return new Tree(implode("\n", $this->run(['git', '--git-dir='.$this->path, 'cat-file', 'tree', $hash])));
    }

    /**
     * @return array<string, Tree>
     */
    public function getTrees(array $hashes): array
    {
        return array_map(
            static fn (string $rawTree) => new Tree($rawTree),
            $this->getObjects($hashes, 'tree'),
        );
    }

    public function commitTree(string $treeHash, string $message, array $parents = [], bool $copyDateFromParents = false): string
    {
        $env = [];

        if ($copyDateFromParents) {
            $date = null;

            foreach ($parents as $parentHash) {
                $parentDate = $this->getCommit($parentHash)->getCommitterDate();

                if (!$date || $parentDate > $date) {
                    $date = $parentDate;
                }
            }

            if ($date) {
                $env['GIT_AUTHOR_DATE'] = $date->format('U O');
                $env['GIT_COMMITTER_DATE'] = $date->format('U O');
            }
        }

        $command = ['git', '--git-dir='.$this->path, 'commit-tree'];

        foreach ($parents as $parent) {
            $command[] = '-p';
            $command[] = $parent;
        }

        $command[] = '-m';
        $command[] = $message;

        $command[] = $treeHash;

        return $this->run($command, $env)[0];
    }

    public function addBranch(string $name, string $hash): self
    {
        $path = $this->path.'/refs/heads/'.$name;

        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(\sprintf('Unable to create directory %s', \dirname($path)));
        }

        file_put_contents($path, $hash);

        return $this;
    }

    public function pushBranch(string $localBranch, string $remote, string $remoteBranch, bool $force = false): self
    {
        $this->pushRefspec('refs/heads/'.$localBranch.':refs/heads/'.$remoteBranch, $remote, $force);

        return $this;
    }

    public function pushBranches(array $branches, bool $force = false): self
    {
        $this->pushRefspecs(
            array_map(
                static fn ($pushBranch) => ['refs/heads/'.$pushBranch[0].':refs/heads/'.$pushBranch[2], $pushBranch[1]],
                $branches,
            ),
            $force,
        );

        return $this;
    }

    public function pushTag(string $localTag, string $remote, string $remoteTag, bool $force = false): self
    {
        $this->pushRefspec('refs/tags/'.$localTag.':refs/tags/'.$remoteTag, $remote, $force);

        return $this;
    }

    public function pushTags(array $tags, bool $force = false): self
    {
        $this->pushRefspecs(
            array_map(
                static fn ($pushTag) => ['refs/tags/'.$pushTag[0].':refs/tags/'.$pushTag[2], $pushTag[1]],
                $tags,
            ),
            $force,
        );

        return $this;
    }

    public function addObject(GitObject $object): self
    {
        $hash = $object->getHash();
        $path = $this->path.'/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2);

        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(\sprintf('Unable to create directory %s', \dirname($path)));
        }

        if (!file_exists($path)) {
            file_put_contents($path, $object->getGitObjectBytes());
        }

        return $this;
    }

    private function pushRefspec(string $refspec, string $remote, bool $force): void
    {
        $command = ['git', '--git-dir='.$this->path, 'push'];

        if ($force) {
            $command[] = '--force';
        }

        $command[] = $remote;
        $command[] = $refspec;

        $this->execute($command);
    }

    private function pushRefspecs(array $refspecsRemote, bool $force): void
    {
        $this->executeConcurrent(
            array_map(
                function ($refspecRemote) use ($force) {
                    $command = ['git', '--git-dir='.$this->path, 'push'];

                    if ($force) {
                        $command[] = '--force';
                    }

                    $command[] = $refspecRemote[1];
                    $command[] = $refspecRemote[0];

                    return $command;
                },
                $refspecsRemote,
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    private function getObjects(array $hashes, string $expectedType): array
    {
        if ([] === $hashes) {
            return [];
        }

        $process = $this->createProcess(['git', '--git-dir='.$this->path, 'cat-file', '--batch']);
        $process->setInput(implode("\n", $hashes)."\n");

        $objects = [];
        $output = $this->execute($process, false);
        $offset = 0;

        foreach ($hashes as $hash) {
            $headerEnd = strpos($output, "\n", $offset);
            $header = false === $headerEnd ? '' : substr($output, $offset, $headerEnd - $offset);

            if (!preg_match('/^([0-9a-f]{40}) ([a-z]+) ([0-9]+)$/', $header, $matches) || $expectedType !== $matches[2]) {
                throw new \RuntimeException(\sprintf('Unable to read %s object %s.', $expectedType, $hash));
            }

            $size = (int) $matches[3];
            $offset = $headerEnd + 1;
            $objects[$matches[1]] = substr($output, $offset, $size);
            $offset += $size + 1;
        }

        return $objects;
    }

    private function run(array $command, array|null $env = null): array
    {
        return explode("\n", $this->execute($command, false, $env));
    }

    private function execute(Process|array $command, bool $streamOutput = true, array|null $env = null): string
    {
        $process = $command instanceof Process ? $command : $this->createProcess($command, $env);

        if ($streamOutput) {
            $this->output->writeln('   $ '.$process->getCommandLine());
            $process->start();

            foreach ($process->getIterator() as $data) {
                $this->output->write($data);
            }

            $process->wait();
        } else {
            // Move the cursor to the beginning of the line
            $this->output->write("\x0D");

            // Erase the line
            $this->output->write("\x1B[2K");
            $this->output->write($process->getCommandLine());
            $process->run();
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    private function createProcess(array $command, array|null $env = null): Process
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(600);

        return $process;
    }

    private function executeConcurrent(array $commands, $exitOnFailure = true): void
    {
        $processes = [];

        foreach ($commands as $command) {
            $this->output->writeln('   $ '.implode(' ', $command));

            $process = $this->createProcess($command);
            $processes[] = $process;
            $process->start();
        }

        foreach ($processes as $process) {
            foreach ($process->getIterator() as $data) {
                $this->output->write($data);
            }

            $process->wait();
        }

        if ($exitOnFailure) {
            foreach ($processes as $process) {
                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }
            }
        }
    }
}
