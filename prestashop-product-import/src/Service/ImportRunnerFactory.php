<?php

namespace ProductImport\Service;

use ProductImport\Repository\CategoryMappingRepository;
use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;

class ImportRunnerFactory
{
    public static function create(): ImportRunner
    {
        $evaluator = new ExpressionEvaluator();
        $normalizer = new CategoryPathNormalizer();
        $products = new ExternalProductRepository();
        $combinations = new ExternalCombinationRepository();
        return new ImportRunner(
            new JsonFetcher(),
            new ProductFilter($evaluator),
            new ProductFieldMapper($evaluator),
            $normalizer,
            new CategoryResolver(new CategoryMappingRepository(), $normalizer),
            new ManufacturerResolver(),
            new ProductImporter($products),
            new VariantFieldMapper($evaluator),
            new AttributeResolver(),
            new CombinationImporter($combinations),
            $products,
            $combinations,
            new ImportRunRepository(),
            new ImportCatalog(),
            new XmlFetcher()
        );
    }
}
