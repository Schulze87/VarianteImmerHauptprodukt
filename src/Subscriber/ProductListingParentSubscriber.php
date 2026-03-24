<?php declare(strict_types=1);

namespace VarianteImmerHauptprodukt\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingParentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingResultEvent::class => 'onListingResult',
        ];
    }

    public function onListingResult(ProductListingResultEvent $event): void
    {
        $result = $event->getResult();
        $entities = $result->getEntities();

        if (!$entities instanceof ProductCollection || $entities->count() === 0) {
            return;
        }

        $parentIds = [];
        foreach ($entities as $product) {
            if ($product instanceof ProductEntity && $product->getParentId()) {
                $parentIds[] = $product->getParentId();
            }
        }

        $parentIds = array_values(array_unique(array_filter($parentIds)));
        if ($parentIds === []) {
            return;
        }

        $criteria = new Criteria($parentIds);
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('seoUrls');

        $parentProducts = $this->productRepository->search(
            $criteria,
            $event->getSalesChannelContext()->getContext()
        )->getEntities();

        $rebuilt = new ProductCollection();
        $alreadyAdded = [];

        foreach ($entities as $product) {
            if (!$product instanceof ProductEntity) {
                continue;
            }

            $displayProduct = $product;

            if ($product->getParentId()) {
                $parent = $parentProducts->get($product->getParentId());

                if ($parent instanceof ProductEntity) {
                    $parent->addExtension('matchedVariant', new ArrayStruct([
                        'id' => $product->getId(),
                        'parentId' => $product->getParentId(),
                        'optionIds' => $product->getOptionIds(),
                        'productNumber' => $product->getProductNumber(),
                    ]));

                    $displayProduct = $parent;
                }
            }

            if (isset($alreadyAdded[$displayProduct->getId()])) {
                continue;
            }

            $alreadyAdded[$displayProduct->getId()] = true;
            $rebuilt->add($displayProduct);
        }

        $result->assign([
            'entities' => $rebuilt,
        ]);
    }
}
