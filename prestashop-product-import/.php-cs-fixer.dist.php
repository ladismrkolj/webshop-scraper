<?php

return (new PhpCsFixer\Config())
    // Sequential mode also works in sandboxes that prohibit local TCP listeners.
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::sequential())
    ->setRules(['@PSR12' => true])
    ->setFinder(PhpCsFixer\Finder::create()->in(__DIR__)->exclude(['vendor', '.composer-cache']));
