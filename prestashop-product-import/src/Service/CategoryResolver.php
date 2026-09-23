<?php

namespace ProductImport\Service;

use ProductImport\Repository\CategoryMappingRepository;

class CategoryResolver
{
    private $mappings;
    private $normalizer;

    public function __construct(CategoryMappingRepository $mappings, CategoryPathNormalizer $normalizer)
    {
        $this->mappings = $mappings;
        $this->normalizer = $normalizer;
    }

    /**
     * @param list<list<string>> $normalizedPaths
     * @return array Commit: list<int>; preview: list<{path, id_category, auto_create}>.
     */
    public function resolve(array $normalizedPaths, int $idSource, ?int $rootCategoryId, bool $commit = true): array
    {
        $resolved = [];
        foreach ($normalizedPaths as $path) {
            if ($path === []) {
                continue;
            }
            $hash = $this->normalizer->hash($path);
            if ($commit) {
                $this->mappings->upsertSeen($idSource, $hash, json_encode($path));
            }
            $override = $this->mappings->findOverride($idSource, $hash);
            if ($override !== null && $override['id_category'] !== null) {
                $resolved[] = $commit ? (int) $override['id_category'] : ['path' => $path, 'id_category' => (int) $override['id_category'], 'auto_create' => false];
                continue;
            }
            $parent = $rootCategoryId ?? (int) \Configuration::get('PS_ROOT_CATEGORY');
            if ($parent <= 0 || !\Validate::isLoadedObject(new \Category($parent))) {
                throw new \RuntimeException('Category root does not exist.');
            }
            foreach ($path as $segment) {
                $parent = $this->resolveChild($parent, $segment, $commit);
                if ($parent === null) {
                    break;
                }
            }
            $resolved[] = $commit ? $parent : ['path' => $path, 'id_category' => $parent, 'auto_create' => $parent === null];
        }

        return $resolved;
    }

    /** Record paths only: never look up overrides or create catalog categories. */
    public function discover(array $normalizedPaths, int $idSource): void
    {
        foreach ($normalizedPaths as $path) {
            if ($path !== []) {
                $this->mappings->upsertSeen($idSource, $this->normalizer->hash($path), json_encode($path, JSON_THROW_ON_ERROR));
            }
        }
    }

    private function resolveChild(int $parent, string $name, bool $commit): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT c.`id_category` FROM `' . _DB_PREFIX_ . 'category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category`'
            . ' AND cl.`id_shop` = c.`id_shop_default`'
            . ' WHERE c.`id_parent` = ' . (int) $parent . " AND BINARY cl.`name` = '" . pSQL($name, true) . "'"
            . ' ORDER BY c.`id_category` ASC'
        );
        if ($id !== false) {
            return (int) $id;
        }
        if (!$commit) {
            return null;
        }
        $category = new \Category();
        $category->id_parent = $parent;
        $category->id_shop_default = (int) \Context::getContext()->shop->id;
        $category->active = true;
        foreach (\Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $category->name[$idLang] = $name;
            $category->link_rewrite[$idLang] = \Tools::str2url($name) ?: 'category';
        }
        if (!$category->add()) {
            throw new \RuntimeException('Unable to create category: ' . $name);
        }

        return (int) $category->id;
    }
}
