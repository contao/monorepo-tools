<?php

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
        $this->execute('git --git-dir='.escapeshellarg($this->path).' init --bare '.escapeshellarg($this->path));

        return $this;
    }

    public function setConfig(string $key, string $value): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path).' config '.escapeshellarg($key).' '.escapeshellarg($value));

        return $this;
    }

    public function removeRefs(): self
    {
        foreach ($this->run('git --git-dir='.escapeshellarg($this->path).' remote') as $remote) {
            if ($remote === '') {
                continue;
            }
            $this->execute('git --git-dir='.escapeshellarg($this->path).' remote remove '.escapeshellarg(trim($remote)));
        }

        (new Filesystem())->remove([
            $this->path.'/refs/heads',
            $this->path.'/refs/remotes',
            $this->path.'/refs/tags',
        ]);

        return $this;
    }

    public function addRemote(string $name, string $url): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path).' remote add '.escapeshellarg($name).' '.escapeshellarg($url));

        return $this;
    }

    public function removeRemote(string $name): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path).' remote rm '.escapeshellarg($name));

        return $this;
    }

    public function fetch(string $remote): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path).' fetch --no-tags '.escapeshellarg($remote));

        return $this;
    }

    public function fetchTags(string $remote, string $prefix)
    {
        $this->execute(
            'git --git-dir='.escapeshellarg($this->path).' fetch --no-tags '.escapeshellarg($remote).' '
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
        foreach ($this->run('git --git-dir='.escapeshellarg($this->path).' branch -r') as $branch) {
            $branch = trim($branch);
            if ($branch === '' || strncmp($branch, $remote.'/', \strlen($remote.'/')) !== 0) {
                continue;
            }
            $branch = substr($branch, \strlen($remote.'/'));
            $branches[$branch] = $this->run('git --git-dir='.escapeshellarg($this->path).' rev-parse '.escapeshellarg($remote.'/'.$branch))[0];
        }

        return $branches;
    }

    /**
     * @return array<string,string>
     */
    public function getTags(string $prefix): array
    {
        $tags = [];
        foreach ($this->run('git --git-dir='.escapeshellarg($this->path).' tag -l '.escapeshellarg($prefix.'*')) as $tag) {
            if ($tag === '') {
                continue;
            }
            $tag = substr(trim($tag), strlen($prefix));
            $tags[$tag] = $this->run('git --git-dir='.escapeshellarg($this->path).' rev-list -n 1 '.escapeshellarg($prefix.$tag))[0];
        }

        return $tags;
    }

    public function addTag(string $name, string $hash): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path).' tag '.escapeshellarg($name).' '.escapeshellarg($hash));

        return $this;
    }

    public function removeTag(string $name): self
    {
        $this->execute('git --git-dir='.escapeshellarg($this->path).' tag -d '.escapeshellarg($name));

        return $this;
    }

    public function getCommit(string $hash): Commit
    {
        return new Commit(implode("\n", $this->run('git --git-dir='.escapeshellarg($this->path).' cat-file commit '.escapeshellarg($hash))));
    }

    public function getTree(string $hash): Tree
    {
        return new Tree(implode("\n", $this->run('git --git-dir='.escapeshellarg($this->path).' cat-file tree '.escapeshellarg($hash))));
    }

    public function commitTree(string $treeHash, string $message, array $parents = [], bool $copyDateFromParents = false): string
    {
        $prefix = '';
        if ($copyDateFromParents) {
            $date = null;
            foreach ($parents as $parentHash) {
                $parentDate = $this->getCommit($parentHash)->getCommitterDate();
                if (!$date || $parentDate > $date) {
                    $date = $parentDate;
                }
            }
            if ($date) {
                $prefix =
                    'GIT_AUTHOR_DATE='.escapeshellarg($date->format('U O'))
                    .' GIT_COMMITTER_DATE='.escapeshellarg($date->format('U O')).' '
                ;
            }
        }
        return $this->run(
            $prefix
            .'git --git-dir='.escapeshellarg($this->path).' commit-tree'
            .' -p '.implode(' -p ', array_map('escapeshellarg', $parents))
            .' -m '.escapeshellarg($message)
            .' '.$treeHash
        )[0];
    }

    public function addBranch(string $name, string $hash): self
    {
        $path = $this->path.'/refs/heads/'.$name;

        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', \dirname($path)));
        }

        file_put_contents($path, $hash);

        return $this;
    }

    public function pushBranch(string $localBranch, string $remote, string $remoteBranch, bool $force = false): self
    {
        $this->pushRefspec('refs/heads/'.$localBranch.':refs/heads/'.$remoteBranch, $remote, $force);

        return $this;
    }

    public function pushTag(string $localTag, string $remote, string $remoteTag, bool $force = false): self
    {
        $this->pushRefspec('refs/tags/'.$localTag.':refs/tags/'.$remoteTag, $remote, $force);

        return $this;
    }

    private function pushRefspec(string $refspec, string $remote, bool $force): void
    {
        $command = 'git --git-dir='.escapeshellarg($this->path).' push';

        if ($force) {
            $command .= ' --force';
        }

        $command .= ' '.escapeshellarg($remote).' '.escapeshellarg($refspec);

        $this->execute($command);
    }

    public function addObject(GitObject $object): self
    {
        $hash = $object->getHash();
        $path = $this->path.'/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2);

        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(sprintf('Unable to create directory %s', \dirname($path)));
        }

        file_put_contents($path, $object->getGitObjectBytes());

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
        foreach ($process->getIterator() as $data) {
            $this->output->write($data);
        }
        $process->wait();

        if ($exitOnFailure && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
