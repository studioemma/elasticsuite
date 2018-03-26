<?php
/**
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade Smile Elastic Suite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteCatalog
 * @author    Romain Ruaud <romain.ruaud@smile.fr>
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2016 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\ElasticsuiteCatalog\Setup;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Catalog\Model\Category;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Indexer\IndexerInterfaceFactory;

/**
 * Catalog installer
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @category Smile
 * @package  Smile\ElasticsuiteCatalog
 * @author   Romain Ruaud <romain.ruaud@smile.fr>
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class InstallData implements InstallDataInterface
{
    /**
     * EAV setup factory
     *
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @var EavSetup
     */
    private $eavSetup;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var \Smile\ElasticsuiteCatalog\Setup\IndexerInterfaceFactory
     */
    private $indexerFactory;

    /**
     * @var Config
     */
    private $eavConfig;

    /**
     * Class Constructor
     *
     * @param EavSetupFactory         $eavSetupFactory Eav setup factory.
     * @param MetadataPool            $metadataPool    Metadata Pool.
     * @param IndexerInterfaceFactory $indexerFactory  Indexer Factory.
     * @param Config                  $eavConfig       EAV Config.
     */
    public function __construct(
        EavSetupFactory $eavSetupFactory,
        MetadataPool $metadataPool,
        IndexerInterfaceFactory $indexerFactory,
        Config $eavConfig
    ) {
        $this->metadataPool    = $metadataPool;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->indexerFactory  = $indexerFactory;
        $this->eavConfig       = $eavConfig;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * Installs Data for the module :
     *  - Create attribute on category to enable/disable name indexation for search
     *  - Update is anchor attribute (hidden frontend input, null source model, enabled by default).
     *
     * @param ModuleDataSetupInterface $setup   The setup interface
     * @param ModuleContextInterface   $context The module Context
     *
     * @return void
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $this->eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
        $this->addCategoryNameSearchAttribute();
        $this->updateCategoryIsAnchorAttribute();
        $this->updateDefaultValuesForNameAttributes();

        /**
         * We do not want to reindex during installation !!!
         */
        //$this->getIndexer('elasticsuite_categories_fulltext')->reindexAll();

        $setup->endSetup();
    }

    /**
     * Create attribute on category to enable/disable name indexation for search.
     *
     * @return void
     */
    private function addCategoryNameSearchAttribute()
    {
        // Installing the new attribute.
        $this->eavSetup->addAttribute(
            Category::ENTITY,
            'use_name_in_product_search',
            [
                'type'       => 'int',
                'label'      => 'Use category name in product search',
                'input'      => 'select',
                'source'     => 'Magento\Eav\Model\Entity\Attribute\Source\Boolean',
                'global'     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'required'   => true,
                'default'    => 1,
                'visible'    => true,
                'note'       => "If the category name is used for fulltext search on products.",
                'sort_order' => 150,
                'group'      => 'General Information',
            ]
        );

        // Set the attribute value to 1 for all existing categories.
        $this->updateAttributeDefaultValue(Category::ENTITY, 'use_name_in_product_search', 1);

        // Mandatory to ensure next installers will have proper EAV Attributes definitions.
        $this->eavConfig->clear();
    }

    /**
     * Update is anchor attribute (hidden frontend input, null source model, enabled by default).
     *
     * @return void
     */
    private function updateCategoryIsAnchorAttribute()
    {
        $this->eavSetup->updateAttribute(Category::ENTITY, 'is_anchor', 'frontend_input', 'hidden');
        $this->eavSetup->updateAttribute(Category::ENTITY, 'is_anchor', 'source_model', null);
        $this->updateAttributeDefaultValue(Category::ENTITY, 'is_anchor', 1, [\Magento\Catalog\Model\Category::TREE_ROOT_ID]);
    }

    /**
     * Update attribute value for an entity with a default value.
     * All existing values are erased by the new value.
     *
     * @param integer|string $entityTypeId Target entity id.
     * @param integer|string $attributeId  Target attribute id.
     * @param mixed          $value        Value to be set.
     * @param array          $excludedIds  List of categories that should not be updated during the process.
     *
     * @return void
     */
    private function updateAttributeDefaultValue($entityTypeId, $attributeId, $value, $excludedIds = [])
    {
        $setup          = $this->eavSetup->getSetup();
        $entityTable    = $setup->getTable($this->eavSetup->getEntityType($entityTypeId, 'entity_table'));
        $attributeTable = $this->eavSetup->getAttributeTable($entityTypeId, $attributeId);

        if (!is_int($attributeId)) {
            $attributeId = $this->eavSetup->getAttributeId($entityTypeId, $attributeId);
        }

        // Retrieve the primary key name. May differs if the staging module is activated or not.
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();

        $entitySelect = $this->getConnection()->select();
        $entitySelect->from(
            $entityTable,
            [new \Zend_Db_Expr("{$attributeId} as attribute_id"), $linkField, new \Zend_Db_Expr("{$value} as value")]
        );

        if (!empty($excludedIds)) {
            $entitySelect->where("entity_id NOT IN(?)", $excludedIds);
        }

        $insertQuery = $this->getConnection()->insertFromSelect(
            $entitySelect,
            $attributeTable,
            ['attribute_id', $linkField, 'value'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );

        $this->getConnection()->query($insertQuery);
    }

    /**
     * DB connection.
     *
     * @return AdapterInterface
     */
    private function getConnection()
    {
        return $this->eavSetup->getSetup()->getConnection();
    }

    /**
     * Update default values for the name field of category and product entities.
     *
     * @return void
     */
    private function updateDefaultValuesForNameAttributes()
    {
        $setup      = $this->eavSetup->getSetup();
        $connection = $setup->getConnection();
        $table      = $setup->getTable('catalog_eav_attribute');

        $attributeIds = [
            $this->eavSetup->getAttributeId(\Magento\Catalog\Model\Product::ENTITY, 'name'),
            $this->eavSetup->getAttributeId(\Magento\Catalog\Model\Category::ENTITY, 'name'),
        ];

        foreach (['is_used_in_spellcheck', 'is_used_in_autocomplete'] as $configField) {
            foreach ($attributeIds as $attributeId) {
                $connection->update(
                    $table,
                    [$configField => 1],
                    $connection->quoteInto('attribute_id = ?', $attributeId)
                );
            }
        }
    }

    /**
     * Retrieve an indexer by its Id
     *
     * @param string $indexerId The indexer Id
     *
     * @return \Magento\Framework\Indexer\IndexerInterface
     */
    private function getIndexer($indexerId)
    {
        return $this->indexerFactory->create()->load($indexerId);
    }
}
