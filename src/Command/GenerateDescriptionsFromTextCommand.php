<?php

declare(strict_types=1);

namespace ACSEO\SyliusAITools\Command;

use ACSEO\SyliusAITools\Manager\DescriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateDescriptionsFromTextCommand extends Command
{
    protected static $defaultName = 'acseo:generate-product-descriptions';

    public function __construct(
        private DescriptionManager $descriptionManager,
        private ProductRepositoryInterface $productRepository,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('acseo:generate-product-descriptions')
            ->setDescription('Generate and update product descriptions for all products.')
            ->setHelp('This command generates and updates product descriptions for all available products based on the given locale, text input, and keywords.')
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL, 'The locale to use for generating descriptions (default is "en")', 'en')
            ->addOption('text', null, InputOption::VALUE_OPTIONAL, 'The base text to generate descriptions')
            ->addOption('keywords', null, InputOption::VALUE_OPTIONAL, 'Comma-separated keywords to use for generating descriptions')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $locale = $input->getOption('locale');
        $text = $input->getOption('text') ?? null;
        $keywords = $input->getOption('keywords') ?: null;

        $symfonyStyle->title(\sprintf('Generating Product Descriptions (Locale: %s)', $locale));

        $descriptions = $this->generateDescriptionsFromText($locale, $text, $keywords);
        $products = $this->getProducts($symfonyStyle);

        if (empty($products)) {
            return Command::SUCCESS;
        }

        foreach ($products as $product) {
            $this->processProduct($product, $descriptions, $locale, $symfonyStyle);
        }

        $symfonyStyle->success('Product descriptions generation completed.');

        return Command::SUCCESS;
    }

    private function generateDescriptionsFromText(string $locale, ?string $text, ?string $keywords): array
    {
        if (!$text) {
            return [];
        }

        return $this->descriptionManager->generateDescriptionsFromText([
            'locale' => $locale,
            'text' => $text,
            'keywords' => $keywords,
        ]);
    }

    private function getProducts(SymfonyStyle $symfonyStyle): array
    {
        $products = $this->productRepository->findAll();
        if (empty($products)) {
            $symfonyStyle->warning('No products found.');
        }

        return $products;
    }

    private function processProduct(ProductInterface $product, array $descriptions, string $locale, SymfonyStyle $symfonyStyle): void
    {
        $symfonyStyle->section(\sprintf('Processing product: %s (ID: %d)', $product->getName(), $product->getId()));

        if (empty($descriptions)) {
            $descriptions = $this->generateProductDescriptions($product, $locale);
        }

        $updatedProduct = $this->descriptionManager->generateAndUpdateProductDescription(
            $descriptions,
            (string) $product->getId(),
            $locale
        );

        $this->persistUpdatedProduct($updatedProduct, $product, $symfonyStyle);
    }

    private function generateProductDescriptions(ProductInterface $product, string $locale): array
    {
        $text = $product->getDescription();
        $keywords = implode(',', array_map(fn ($taxon) => $taxon->getCode(), $product->getTaxons()->toArray()));

        return $this->descriptionManager->generateDescriptionsFromText([
            'locale' => $locale,
            'text' => $text,
            'keywords' => $keywords,
        ]);
    }

    private function persistUpdatedProduct(?ProductInterface $updatedProduct, ProductInterface $product, SymfonyStyle $symfonyStyle): void
    {
        if ($updatedProduct) {
            $this->entityManager->persist($updatedProduct);
            $symfonyStyle->success(\sprintf('Description updated for product: %s (ID: %d)', $product->getName(), $product->getId()));

            return;
        }

        $symfonyStyle->error(\sprintf('Failed to update description for product: %s (ID: %d)', $product->getName(), $product->getId()));
    }
}
