<?php

declare(strict_types=1);

namespace ACSEO\SyliusAITools\Form;

use Sylius\Component\Core\Model\Product;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

class ProductDescriptionFromPicturesFormType extends AbstractProductDescriptionFormType
{
    protected function buildCustomFormFields(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pictures', FileType::class, [
                'label' => 'sylius.ui.form.image_label',
                'multiple' => true,
                'required' => false,
            ])
            ->add('preloaded_images', HiddenType::class, [
                'data' => $this->getImages($options),
                'mapped' => false,
            ])
        ;
    }

    private function getImages(array $options): ?string
    {
        $product = $options['product'];
        if (!$product instanceof Product) {
            return null;
        }

        $virtualHost = $this->getVirtualHostFromProduct($product);
        if (null === $virtualHost) {
            return null;
        }

        $images = $this->generateImageUrls($product, $virtualHost);

        return !empty($images) ? implode(',', $images) : null;
    }

    private function getVirtualHostFromProduct(Product $product): ?string
    {
        foreach ($product->getChannels() as $channel) {
            if ($hostName = $channel->getHostname()) {
                return rtrim($hostName, '/');
            }
        }

        return null;
    }

    private function generateImageUrls(Product $product, string $virtualHost): array
    {
        if (!preg_match('#^https?://#', $virtualHost)) {
            $virtualHost = 'http://' . $virtualHost;
        }

        return array_map(function ($image) use ($virtualHost) {
            $imagePath = ltrim($image->getPath() ?? '', '/');

            return \sprintf('%s/media/cache/resolve/sylius_shop_product_original/%s', $virtualHost, $imagePath);
        }, $product->getImages()->toArray());
    }
}
