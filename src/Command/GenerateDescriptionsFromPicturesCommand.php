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
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GenerateDescriptionsFromPicturesCommand extends Command
{
    protected static $defaultName = 'acseo:generate-product-descriptions-from-pictures';

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
            ->setName('acseo:generate-product-descriptions-from-pictures')
            ->setDescription('Generate and update product descriptions for all products using pictures.')
            ->setHelp('This command generates and updates product descriptions for all available products based on provided pictures and locale.')
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL, 'The locale to use for generating descriptions (default is "en")', 'en')
            ->addOption('pictures', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of picture URLs or paths to use for generating descriptions')
            ->addOption('keywords', null, InputOption::VALUE_OPTIONAL, 'Comma-separated keywords to use for generating descriptions')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $locale = $input->getOption('locale');
        $pictures = $this->getPicturesFromInput($input);
        $keywords = $input->getOption('keywords') ?: null;

        if (empty($pictures)) {
            $symfonyStyle->warning('No pictures found.');
        }

        $pictures = $this->convertUrlsToUploadedFiles($pictures);
        $symfonyStyle->title(\sprintf('Generating Product Descriptions from Pictures (Locale: %s)', $locale));

        $products = $this->productRepository->findAll();
        if (0 === \count($products)) {
            $symfonyStyle->warning('No products found.');

            return Command::SUCCESS;
        }

        $descriptions = $this->getDescriptions($pictures, $locale, $keywords);

        $this->processProducts($products, $descriptions, $symfonyStyle, $locale);

        $symfonyStyle->success('Product descriptions generation from pictures completed.');

        return Command::SUCCESS;
    }

    private function getPicturesFromInput(InputInterface $input): array
    {
        return $input->getOption('pictures') ? array_map('trim', explode(',', $input->getOption('pictures'))) : [];
    }

    private function getDescriptions(array $pictures, string $locale, ?string $keywords): array
    {
        return empty($pictures)
            ? []
            : $this->descriptionManager->generateDescriptionsFromText([
                'locale' => $locale,
                'pictures' => $pictures,
                'keywords' => $keywords,
            ]);
    }

    private function processProducts(array $products, array $descriptions, SymfonyStyle $symfonyStyle, string $locale): void
    {
        foreach ($products as $product) {
            if ($product instanceof ProductInterface) {
                $this->processProduct($product, $descriptions, $symfonyStyle, $locale);
            }
        }
    }

    private function processProduct(ProductInterface $product, array $descriptions, SymfonyStyle $symfonyStyle, string $locale): void
    {
        $symfonyStyle->section(\sprintf('Processing product: %s (ID: %d)', $product->getName(), $product->getId()));

        $updatedProduct = $this->descriptionManager->generateAndUpdateProductDescription(
            $descriptions,
            (string) $product->getId(),
            $locale
        );

        $this->persistUpdatedProduct($updatedProduct, $product, $symfonyStyle);
    }

    private function persistUpdatedProduct(?ProductInterface $updatedProduct, ProductInterface $product, SymfonyStyle $symfonyStyle): void
    {
        if ($updatedProduct instanceof ProductInterface) {
            $this->entityManager->persist($updatedProduct);
            $symfonyStyle->success(\sprintf('Description updated for product: %s (ID: %d)', $product->getName(), $product->getId()));

            return;
        }

        $symfonyStyle->error(\sprintf('Failed to update description for product: %s (ID: %d)', $product->getName(), $product->getId()));
    }

    private function convertUrlsToUploadedFiles(array $pictureUrls): array
    {
        $uploadedFiles = [];
        foreach ($pictureUrls as $url) {
            $fileContents = file_get_contents($url);
            if (false !== $fileContents) {
                $tempFile = tempnam(sys_get_temp_dir(), 'product_picture_');
                file_put_contents($tempFile, $fileContents);
                /** @var string|null $mimeType */
                $mimeType = mime_content_type($tempFile);
                $uploadedFiles[] = new UploadedFile($tempFile, basename($url), $mimeType, null, true);
            }
        }

        return $uploadedFiles;
    }
}
