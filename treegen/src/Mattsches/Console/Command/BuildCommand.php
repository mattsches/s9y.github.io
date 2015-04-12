<?php
namespace Mattsches\Console\Command;

use Knp\Menu\Matcher\Matcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Knp\Menu\MenuFactory;
use Knp\Menu\MenuItem;
use Knp\Menu\Renderer\ListRenderer;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Class BuildCommand
 * @package Mattsches\Console\Command
 */
class BuildCommand extends Command
{
    /**
     * @var MenuItem
     */
    private $siteMap;

    /**
     * @var string
     */
    private $rootPath;

    /**
     * Configure command
     */
    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds HTML tree site map')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to root directory',
                '_site' // Default directory
            );
    }

    /**
     * Execute command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->rootPath = realpath($input->getArgument('path'));

        $factory = new MenuFactory();
        $this->siteMap = $factory->createItem('S9y docs');

        $this->addDirectoryNodes();
        $this->addFileNodes();

        $this->writeSiteMap();
    }

    /**
     * @param string $tree
     * @return string
     */
    private function wrapTreeInHtml($tree)
    {
        $before = <<<EOT
<!DOCTYPE html>
<html>
<head lang="en">
	<meta charset="UTF-8">
	<title></title>
</head>
<body>

EOT;
        $after = <<<EOT
</body>
</html>
EOT;

        return $before . $tree . $after;
    }

    /**
     * @return Finder
     */
    private function getFileFinder()
    {
        $fileFinder = new Finder();
        $fileFinder->files()->name('*.html')->in($this->rootPath);

        return $fileFinder;
    }

    /**
     * @return Finder
     */
    private function getDirectoryFinder()
    {
        $dirFinder = new Finder();
        $dirFinder->directories()->in($this->rootPath);

        return $dirFinder;
    }

    /**
     * @param SplFileInfo $node
     * @return string
     */
    private function getChildUri($node)
    {
        return $this->rootPath . '/' . $node->getRelativePath() . '/' . $node->getFilename();
    }

    /**
     * Add directory nodes
     *
     * @return void
     */
    private function addDirectoryNodes()
    {
        /** @var SplFileInfo $dir */
        foreach ($this->getDirectoryFinder() as $dir) {
            if ($dir->isDir() && $dir->getRelativePath() === '') {
                // root dirs
                $this->siteMap->addChild(
                    $dir->getFilename(),
                    array('uri' => $this->getChildUri($dir))
                );
            } elseif ($dir->isDir()) {
                $this->siteMap->getChild($dir->getRelativePath())->addChild(
                    $dir->getFilename(),
                    array('uri' => $this->getChildUri($dir))
                );
            }
        }
    }

    /**
     * Add file nodes
     *
     * @return void
     */
    private function addFileNodes()
    {
        /** @var SplFileInfo $file */
        foreach ($this->getFileFinder() as $file) {
            $relPath = $file->getRelativePath();
            $relPathParts = explode(DIRECTORY_SEPARATOR, $relPath);
            $currentNode = null;
            foreach ($relPathParts as $part) {
                if (!$currentNode) {
                    $currentNode = $this->siteMap->getChild($part);
                } else {
                    /** @var MenuItem $currentNode */
                    $currentNode = $currentNode->getChild($part);
                }
            }
            if ($currentNode) {
                $fileNode = $currentNode->addChild(
                    $file->getFilename(),
                    array('uri' => $this->getChildUri($file))
                );
                $html = $file->getContents();
                $crawler = new Crawler($html);

                // Level 1 headings
                $crawler->filter('h1')->each(
                    function (Crawler $h1node) use ($fileNode) {
                        if ($h1node->text() == 'TOC') {
                            return;
                        }
                        $menuH1Node = $fileNode->addChild($h1node->text());

                        // Level 2 headings
                        $h1node->siblings()->filter('h2')->each(
                            function (Crawler $h2node) use ($menuH1Node) {
                                $menuH1Node->addChild($h2node->text());
                            }
                        );
                    }
                );
            }
        }
    }

    /**
     * Write site map
     *
     * @return void
     */
    private function writeSiteMap()
    {
        $renderer = new ListRenderer(new Matcher());
        $tree = $renderer->render($this->siteMap);
        $fs = new Filesystem();
        try {
            $fs->dumpFile($this->rootPath . '/sitemap.html', $this->wrapTreeInHtml($tree));
        } catch (IOExceptionInterface $e) {
            echo "An error occurred while writing a file at " . $e->getPath();
        }
    }
}