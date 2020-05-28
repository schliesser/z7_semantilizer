<?php

namespace Zeroseven\Semantilizer\Utilities;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Zeroseven\Semantilizer\FixedTitle\FixedTitleInterface;
use Zeroseven\Semantilizer\Models\ContentCollection;
use Zeroseven\Semantilizer\Models\ContentData;
use Zeroseven\Semantilizer\Models\PageData;

class CollectContentUtility
{

    /** @var string */
    private const TABLE = 'tt_content';

    /** @var array */
    private $tsConfig;

    /** @var PageData */
    private $page;

    public function __construct(PageData $page, array $tsConfig)
    {
        $this->page = $page;
        $this->tsConfig = $tsConfig;
    }

    protected function getFixedTitle(ContentCollection $contentCollection = null): ?ContentData
    {
        $fixedTitle = null;

        if ($hooks = $GLOBALS['TYPO3_CONF_VARS']['EXT']['z7_semantilizer']['fixedPageTitle'] ?? null) {

            // Sort them by their keys
            ksort($hooks);

            // Set parameter for the hook
            $hookParameter = [
                'page' => $this->page,
                'tsConfig' => $this->tsConfig,
                'contentCollection' => $contentCollection
            ];

            // Loop them
            foreach ($hooks as $className) {
                if (empty($fixedTitle) && class_exists($className) && is_subclass_of($className, FixedTitleInterface::class)) {
                    if ($overrideTitle = GeneralUtility::callUserFunction($className . '->get', $hookParameter, $this)) {
                        $fixedTitle = $overrideTitle;
                    }
                }
            }
        }

        if ($fixedTitle) {

            $row = [
                'header' => $fixedTitle,
                'header_type' => 1,
                '__fixed' => true
            ];

            foreach (ContentData::REQUIRED_FIELDS as $key) {
                $row[$key] = $row[$key] ?? null;
            }

            return GeneralUtility::makeInstance(ContentData::class, $row);
        }

        return null;
    }

    public function getCollection(): ContentCollection
    {

        // Create collection
        $contentCollection = GeneralUtility::makeInstance(ContentCollection::class);

        // Get instance of the query builder
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);

        // Get results from the database
        $results = $queryBuilder->select('uid', 'header', 'header_type', 'cType')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gt('header_type', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->lt('header_layout', $queryBuilder->createNamedParameter(100, \PDO::PARAM_INT)),
                $queryBuilder->expr()->neq('header', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($this->page->getL10nParent() ?: $this->page->getUid(), \PDO::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([(string)$this->page->getSysLanguageUid(), '-1'], Connection::PARAM_INT_ARRAY)),

                // Todo: Add condition around this query
                $queryBuilder->expr()->notIn('CType', array_map(static function ($value) use ($queryBuilder) {
                    return $queryBuilder->createNamedParameter($value, \PDO::PARAM_STR);
                }, $this->tsConfig['ignoreCTypes'] ? GeneralUtility::trimExplode(',', $this->tsConfig['ignoreCTypes']) : ['__']))
            )
            ->orderBy('sorting')
            ->execute()
            ->fetchAll() ?: [];

        // Add some links and attributes to the content elements
        foreach ($results as $row) {
            $contentElement = GeneralUtility::makeInstance(ContentData::class, $row);
            $contentCollection->append($contentElement);
        }

        // Prepend Title
        if($fixedTitle = $this->getFixedTitle($contentCollection)) {
            $contentCollection->prepend($fixedTitle);
        }

        return $contentCollection;
    }

}
