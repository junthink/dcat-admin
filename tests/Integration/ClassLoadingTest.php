<?php

namespace Dcat\Admin\Tests;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class ClassLoadingTest extends TestCase
{
    public function test_all_package_types_load_with_current_framework_signatures(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser(new NameResolver());
        $finder = new NodeFinder();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $nodes = $traverser->traverse($parser->parse(file_get_contents($file->getPathname())));
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
                if (! isset($node->namespacedName)) {
                    continue;
                }
                $name = $node->namespacedName->toString();
                $this->assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name), $name);
            }
        }
    }
}
