<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Git;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Repository
{
    private $path;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct(string $path, OutputInterface $output)
    {
        $this->path = $path;
        $this->output = $output;
    }

    /**
     * @return static
     */
    public function init(): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path.'/.git').' init '.escapeshellarg($this->path));

        return $this;
    }

    public function addRemote(string $name, string $url): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path.'/.git').' remote add '.escapeshellarg($name).' '.escapeshellarg($url));

        return $this;
    }

    public function fetch(string $remote): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path.'/.git').' fetch --no-tags '.escapeshellarg($remote));

        return $this;
    }

    public function fetchTags(string $remote, string $prefix)
    {
        $this->execute(
            'git --git-dir='.escapeshellarg($this->path.'/.git').' fetch --no-tags '.escapeshellarg($remote).' '
            .escapeshellarg('+refs/tags/*:refs/tags/'.$prefix.'*')
        );

        return $this;
    }

    /**
     * @return array<string,string>
     */
    public function getRemoteBranches(string $remote): array
    {
        $branches = [];
        foreach ($this->run('git --git-dir='.escapeshellarg($this->path.'/.git').' branch -r | grep '.escapeshellarg($remote.'/*')) as $branch) {
            if ($branch === '') {
                continue;
            }
            $branch = substr(trim($branch), strlen($remote) + 1);
            $branches[$branch] = $this->run('git --git-dir='.escapeshellarg($this->path.'/.git').' rev-parse '.escapeshellarg($remote.'/'.$branch))[0];
        }

        return $branches;
    }

    public function getCommit(string $hash): Commit
    {
        return new Commit(implode("\n", $this->run('git --git-dir='.escapeshellarg($this->path.'/.git').' cat-file commit '.escapeshellarg($hash))));
    }

    public function getTree(string $hash): Tree
    {
        return new Tree(implode("\n", $this->run('git --git-dir='.escapeshellarg($this->path.'/.git').' cat-file -p '.escapeshellarg($hash))));
    }

    public function addBranch(string $name, string $hash): self
    {
        $path = $this->path.'/.git/refs/heads/'.$name;

        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', \dirname($path)));
        }

        file_put_contents($path, $hash);

        return $this;
    }

    public function addCommit(Commit $commit): self
    {
        $hash = $commit->getHash();
        $path = $this->path.'/.git/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2);

        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', \dirname($path)));
        }

        file_put_contents($path, $commit->getGitObjectBytes());

        return $this;
    }

    private function run($command, $exitOnFailure = true)
    {
        // Move the cursor to the beginning of the line
        $this->output->write("\x0D");

        // Erase the line
        $this->output->write("\x1B[2K");

        $this->output->write($command);

        $process = new Process($command);
        $process->run();

        if ($exitOnFailure && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return explode("\n", $process->getOutput());
    }

    private function execute($command, $exitOnFailure = true)
    {
        $this->output->writeln('   $ '.$command);

        $process = new Process($command);
        $process->start();
        $this->output->write($process->getIterator());
        $process->wait();

        if ($exitOnFailure && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
