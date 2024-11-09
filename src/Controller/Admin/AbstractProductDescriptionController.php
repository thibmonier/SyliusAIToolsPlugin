<?php

declare(strict_types=1);

namespace ACSEO\SyliusAITools\Controller\Admin;

use ACSEO\SyliusAITools\Manager\DescriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractProductDescriptionController extends AbstractController
{
    public function __construct(
        protected DescriptionManager $descriptionManager,
        protected TranslatorInterface $translator,
        protected EntityManagerInterface $entityManager
    ) {
    }

    public function index(Request $request): Response
    {
        $locale = $request->query->get('locale', '');
        $resource = $request->query->get('resource', '');

        $form = $this->createForm($this->getFormType(), null, [
            'locale' => $locale,
            'resource' => $resource,
            'product' => $this->entityManager->find(ProductInterface::class, (int) $resource),
        ]);

        $form->handleRequest($request);

        if ($this->isFormInvalid($form)) {
            return $this->render($this->getTemplatePath(), [
                'form' => $form->createView(),
                'locale' => $locale,
                'resource' => $resource,
            ]);
        }

        return $this->handleValidForm($request, $form->getData());
    }

    private function isFormInvalid(FormInterface $form): bool
    {
        return !$form->isSubmitted() || !$form->isValid();
    }

    private function handleValidForm(Request $request, array $data): Response
    {
        $this->processPictures($request, $data);
        $this->processPreloadedImages($request, $data);

        $descriptions = $this->generateDescriptions($data);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['descriptions' => $descriptions]);
        }

        return $this->processProductUpdate($data, $descriptions);
    }

    private function processPictures(Request $request, array &$data): void
    {
        $pictures = $request->files->get('product_description_from_pictures_form', null);
        if (null !== $pictures) {
            $data['pictures'] = $pictures['pictures'];
        }
    }

    private function processPreloadedImages(Request $request, array &$data): void
    {
        $preloadedImages = $request->get('product_description_from_pictures_form')['preloaded_images'] ?? null;
        if ($preloadedImages) {
            $preloadedImageUrls = explode(',', $preloadedImages);
            $preloadedFiles = [];

            foreach ($preloadedImageUrls as $imageUrl) {
                $uploadedFile = $this->createUploadedFileFromUrl($imageUrl);
                $preloadedFiles[] = $uploadedFile;
            }

            $data['preloaded_images'] = $preloadedFiles;
        }
    }

    private function createUploadedFileFromUrl(string $imageUrl): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'preloaded_');
        file_put_contents($tempFile, file_get_contents($imageUrl));

        /** @var string|null $mimeType */
        $mimeType = mime_content_type($tempFile);

        return new UploadedFile(
            $tempFile,
            basename($imageUrl),
            $mimeType,
            null,
            true
        );
    }

    private function processProductUpdate(array $data, array $descriptions): Response
    {
        $product = $this->descriptionManager->generateAndUpdateProductDescription(
            $descriptions,
            $data['resource'] ?? '',
            $data['locale'] ?? ''
        );

        if ($product instanceof ProductInterface) {
            $this->addFlash('success', $this->translator->trans('product.update.success'));

            return $this->redirectToRoute('sylius_admin_product_update', ['id' => $product->getId()]);
        }

        $this->addFlash('error', $this->translator->trans('product.not_found'));

        return $this->redirectToRoute('sylius_admin_product_index');
    }

    /**
     * Get the form type class to be used in the controller.
     */
    abstract protected function getFormType(): string;

    /**
     * Get the path to the template to be rendered.
     */
    abstract protected function getTemplatePath(): string;

    /**
     * Generate descriptions based on the data provided.
     */
    abstract protected function generateDescriptions(array $data): array;
}
