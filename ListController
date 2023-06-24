<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\CustomSOLRService;
use App\Services\MediaService;
use Ibexa\Bundle\Core\Controller;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\ProductCatalog\ContentAwareProductInterface;
use Ibexa\Contracts\ProductCatalog\ProductServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class listController extends Controller
{
	private ProductServiceInterface $productService;
	private MediaService $mediaService;
	private TranslatorInterface $translator;
	private CustomSOLRService $solr;
	private Request $request;



	public function __construct(
		ProductServiceInterface $productService,
		MediaService $mediaService,
		TranslatorInterface $translator,
		CustomSOLRService $solr,
		RequestStack $requestStack
	) {
		$this->productService = $productService;
		$this->mediaService = $mediaService;
		$this->translator = $translator;
		$this->solr = $solr;
		$this->request = $requestStack->getCurrentRequest();
	}

	private function getValueForSectionStart($content, $productNumber)
	{

		$productNumberTranslationAndNumber = $this->translator->trans('product_type.product_number', [], 'products') .  ' ' . $productNumber;

		return [
			"template" => "@organisms/SectionStart/SectionStart.twig",
			"data" => [
				"intro" => [
					"headline" => [
						"tag" => "h2",
						"accent_text" => $this->translator->trans('product_type.spare_parts', [], 'products'),
						"text" => $content->getFieldValue("name")
					],
					"text" => [
						"size" => "M",
						"content" => $productNumberTranslationAndNumber
					]
				]
			]
		];
	}

	private function getOptionsForVersionSelect($productnumber, $version)
	{
		$language = $this->request->getLocale();
		$existingVersions = $this->solr->getAvailableVersionsForProductnumber($productnumber, $language);
		$spareParts = null;

		foreach ($existingVersions as $key => $versionValue) {
			$sparePart = [
				"selected" => strtolower($versionValue) === strtolower($version),
				"value" => strtolower($versionValue),
				"text" => $versionValue
			];

			$spareParts[] = $sparePart;
		}

		if (!$spareParts) {
			throw $this->createNotFoundException('The spare parts does not exist');
		}

		return $spareParts;
	}

	public function getImageData($content, $productnumber, $version)
	{

		$elementIds = [];
		try {
			$elementIds = $content->getFieldValue('exploded_drawings')->destinationContentIds;
		} catch (\Exception $e) {
			echo 'No Exploded Drawing provided!',  $e->getMessage(), "\n";
		}

		$variationImage = null;
		$name = null;

		foreach ($elementIds as $elementId) {
			$image = $this->mediaService->getRelatedMedia($elementId, 'PartList', ["large", "medium", "xlarge", "small", "xsmall"]);
			if (isset($image["data"]["name"]) && strtolower($image["data"]["name"]) === strtolower($version)) {
				$variationImage = $image["data"];
				$name = $image["data"]["name"];
			}
		}

		return [
			"contentImage_name" => [
				"tag" => "h4",
				"text" => $this->translator->trans('product_type.spare_parts', [], 'products') . " " . $productnumber . " " . $this->translator->trans('product_type.version', [], 'products') . " " . strtoupper($version)
			],
			"contentImage" => [
				"image" => $variationImage
			]
		];
	}

	private function getSparePartsForTable($spareParts)
	{
		$orderedSpareparts = [];
		$data = [];

		foreach ($spareParts as  $sparePart) {
			$orderedSpareparts[$sparePart["sparepartPosition"]] = $sparePart;
		}
		ksort($orderedSpareparts);

		foreach ($orderedSpareparts as $sparePart) {

			$row = [
				[
					"template" => "@atoms/Copytext/Copytext.twig",
					"data" => [
						"text" =>  $sparePart["sparepartPosition"],
						"size" => "s"
					]
				],
				[
					"template" => "@atoms/Copytext/Copytext.twig",
					"data" => [
						"text" =>  $sparePart["sparepartName"],
						"size" => "s"
					]
				],
				[
					"template" => "@atoms/Copytext/Copytext.twig",
					"data" => [
						"text" =>  $sparePart["sparepartNumber"],
						"size" => "s"
					]
				],
				[
					"template" => "@atoms/ButtonPrimary/ButtonPrimary.twig",
					"data" => [
						"text" => $this->translator->trans('product_type.spartslist_button_label', [], 'products'),
						"size" => "s",
						"href" => $this->translator->trans('product_type.sparepart_detail_url', [], 'products') ."/". $sparePart["sparepartNumber"]
					]
				]
			];
			$data[] = $row;
		}

		return $data;
	}


	public function showSparepartListAction($productnumber, $version = NULL)
	{
		if ($version === NULL) {
			$path = $this->request->attributes->get('semanticPathinfo');
			$response = $this->redirect($path . "/a");
			return $response;
		}
		$sparepartsList = $this->solr->getRelatedSparepartsForProductnumber($productnumber, strtoupper($version), $this->request->getLocale());

		if (empty($sparepartsList) && strtolower($version) === "a") {
			throw $this->createNotFoundException('Product does not exist');
		} elseif (empty($sparepartsList)) {
			// if there are not sparelist(the provided version does not exist) the version "a"  should be loaded
			$response = $this->redirect("a");
			return $response;
		}

		/** @var ContentAwareProductInterface */
		$product = $this->productService->getProduct($productnumber);
		$content = $product->getContent();

		$imageData = $this->getImageData($content, $productnumber, $version);

		$data["sparepartsList"] = [
			"versions_label" => $this->translator->trans('product_type.version_device', [], 'products'),
			"versions" => [
				"options" => $this->getOptionsForVersionSelect($productnumber, $version)
			],
			"tooltip" => [
				"text" => $this->translator->trans('product_type.tooltip', [], 'products'),
				"image" => [
					"alt" => "",
					"sources" => [
						"xsmall" => ["src" => "/images/tooltip.png"]
					]
				]
			],
			"contentImage_name" => $imageData["contentImage_name"],
			"contentImage" => $imageData["contentImage"],
			"table" => [
				"head" => [
					$this->translator->trans('product_type.position', [], 'products'),
					$this->translator->trans('product_type.product_name', [], 'products'),
					$this->translator->trans('product_type.product_number', [], 'products'),
					""
				],
				"body" => $this->getSparePartsForTable($sparepartsList[$productnumber])
			]
		];

		$data["productInfo"] = $this->getValueForSectionStart($content, $productnumber);

		return $this->render('@ibexadesign/full/spareparts_list.html.twig', [
			"sparepartsList" => $data["sparepartsList"],
			"productInfo" => $data["productInfo"],
			"printButton" => $this->translator->trans('product_type.button_print_list', [], 'products')
		]);
	}
}
