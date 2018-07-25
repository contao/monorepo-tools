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
use Contao\Monorepo\Git\Tree;

class Splitter
{
    private $monorepoUrl;
    private $repoUrlsByFolder;

    public function __construct(string $monorepoUrl, array $repoUrlsByFolder)
    {
        $this->monorepoUrl = $monorepoUrl;
        $this->repoUrlsByFolder = $repoUrlsByFolder;
    }

    public function splitRepos()
    {
        $dir = __DIR__.'/split-tmp';
        $this->execute('rm -rf '.escapeshellarg($dir));
        mkdir($dir, 0777, true);
        $this->execute('cd '.escapeshellarg($dir));
        chdir($dir);
        $this->execute('git init .');
        $this->execute('git remote add mono '.escapeshellarg($this->monorepoUrl));
        $this->execute('git fetch --no-tags mono');
        $this->execute('git fetch --no-tags mono'.' "+refs/tags/*:refs/tags/remote/mono/*"');

        $branchCommits = [];
        foreach ($this->run('git branch -r | grep '.escapeshellarg('mono/*')) as $branch) {
            if ($branch === '') {
                continue;
            }
            $branch = substr(trim($branch), strlen('mono') + 1);
            $branchCommits[$branch] = $this->run('git rev-parse '.escapeshellarg('mono/'.$branch))[0];
        }

        $commitObjects = $this->readCommits(array_values($branchCommits));

        if (empty($commitObjects)) {
            $this->exitWithFailure("No commits found for: ".print_r($branchCommits, true));
        }

        $hashMapping = $this->splitCommits($commitObjects, $this->repoUrlsByFolder);

        if (empty($hashMapping)) {
            $this->exitWithFailure("No hash mapping for commits: ".print_r($commitObjects, true));
        }

        foreach ($branchCommits as $branch => $commit) {
            foreach ($this->repoUrlsByFolder as $subRepo => $remote) {
                if (isset($hashMapping[$subRepo][$commit])) {
                    $this->execute('mkdir -p '.escapeshellarg(dirname('.git/refs/heads/'.$subRepo.'/'.$branch)));
                    $this->execute('echo '.escapeshellarg($hashMapping[$subRepo][$commit]).' > '.escapeshellarg('.git/refs/heads/'.$subRepo.'/'.$branch));
                }
            }
        }

        foreach ($GLOBALS['newCommits'] as $newCommit) {
            $hash = $newCommit->getHash();
            $path = __DIR__.'/split-tmp/.git/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $newCommit->getGitObjectBytes());
        }

        /*
        while (count($GLOBALS['newCommits'])) {
            $commands = [];
            for ($i=0; $i < 200 && count($GLOBALS['newCommits']); $i++) {
                $commands[] = 'echo '.bin2hex(array_shift($GLOBALS['newCommits'])).' | xxd -r -p | git hash-object -t commit -w --stdin --literally';
            }
            $this->run(implode(' ; ', $commands));
        }
        */

        /*
        $this->run(
            'echo '
            .implode(
                ' | xxd -r -p | git hash-object -t commit -w --stdin; echo ',
                array_map('bin2hex', $GLOBALS['newCommits'])
            )
            .' | xxd -r -p | git hash-object -t commit -w --stdin'
        );
        */

        #$this->execute('git remote rm '.escapeshellarg($subFolder));
    }

    private function splitCommits(array $commitObjects, array $subRepos)
    {
        $hashMapping = [];
        $pending = array_keys($commitObjects);
        while(count($pending)) {
            $current = array_pop($pending);
            foreach ($subRepos as $subRepo => $remote) {
                if (isset($hashMapping[$subRepo][$current])) {
                    continue 2;
                }
            }
            $missingParents = [];
            foreach ($commitObjects[$current]->getParentHashes() as $parent) {
                foreach ($subRepos as $subRepo => $remote) {
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
        foreach ($subRepos as $subRepo => $remote) {
            if (!$treeObject->getSubtreeHash($subRepo)) {
                continue;
            }
            $hashMapping[$subRepo][$commitHash] = $this->createNewCommit($commitHash, $treeObject->getSubtreeHash($subRepo), $hashMapping[$subRepo]);
            $failure = false;
        }
        if ($failure) {
            var_export($treeObject);
            throw new Exception('No subfolder found in '.$commitHash);
        }
    }

    private function createNewCommit(string $commit, string $tree, &$hashMapping)
    {
        $commitObject = $this->getCommitObject($commit);

        $newParents = [];
        foreach ($commitObject->getParentHashes() as $parent) {
            if (isset($hashMapping[$parent]) && !in_array($hashMapping[$parent], $newParents)) {
                $newParents[] = $hashMapping[$parent];
            }
        }

        $commitObject = $commitObject->withNewTreeAndParents($tree, $newParents);

        if (count($commitObject->getParentHashes()) === 1 && $this->getTreeHashFromCommitHash($commitObject->getParentHashes()[0]) === $tree) {
            return $commitObject->getParentHashes()[0];
        }

        $GLOBALS['newCommits'][] = $commitObject;
        $newHash = $commitObject->getHash();

        $GLOBALS['cache']['commits'][$newHash] = $commitObject;

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

    private function getTreeObject($hash)
    {
        if (isset($GLOBALS['cache']['trees'][$hash])) {
            return $GLOBALS['cache']['trees'][$hash];
        }

        $tree = new Tree(implode("\n", $this->run('git cat-file -p '.$hash)));

        $GLOBALS['cache']['trees'][$hash] = $tree;

        return $tree;
    }

    private function getTreeHashFromCommitHash($commitHash)
    {
        return $this->getCommitObject($commitHash)->getTreeHash();
    }

    private function getCommitObject($hash)
    {
        if (isset($GLOBALS['cache']['commits'][$hash])) {
            return $GLOBALS['cache']['commits'][$hash];
        }

        $commit = new Commit(implode("\n", $this->run('git cat-file commit '.$hash)));

        $GLOBALS['cache']['commits'][$hash] = $commit;

        return $commit;
    }

    private function run($command, $exitOnFailure = true)
    {
        #echo("   $ ".$command."\n");
        echo '.';
        ob_start();
        system($command, $exitCode);
        $output = explode("\n", ob_get_clean());
        if ($exitCode !== 0 && $exitOnFailure) {
            $this->exitWithFailure('Failed, exit code ' . var_export($exitCode, true));
        }
        return $output;
    }

    private function execute($command, $exitOnFailure = true)
    {
        echo("   $ ".$command."\n");
        system($command, $exitCode);
        if ($exitCode !== 0 && $exitOnFailure) {
            $this->exitWithFailure('Failed, exit code ' . var_export($exitCode, true));
        }
    }

    private function exitWithFailure($message = '')
    {
        echo "\033[41m\n\n"; // red background
        echo "FAILURE: ".$message;
        echo "\n\033[0m\n";
        exit(1);
    }

}
