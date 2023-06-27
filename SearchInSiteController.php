<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\CustomSOLRService;
use App\Services\LanguageService;
use App\Services\DownloadService;
use App\Services\MediaService;
use App\Services\RichtextConverterService;
use Ibexa\Bundle\Core\Controller;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\ProductCatalog\ProductServiceInterface;
use Ibexa\Contracts\ProductCatalog\Values\Product\ProductQuery;
use Ibexa\Core\MVC\Symfony\View\ContentView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;


class SearchInSiteController extends Controller
{
	private ProductServiceInterface $productService;
	private TranslatorInterface $translator;
	private ContentService $contentService;
	private LocationService $locationService;
	private Request $request;
	private RichtextConverterService $richtextConverter;
	private MediaService $mediaService;
	private DownloadService $downloadService;

	protected RouterInterface $router;
	private CustomSOLRService $solr;
	private LanguageService $languageService;


	public function __construct(
		RouterInterface $router,
		ProductServiceInterface $productService,
		TranslatorInterface $translator,
		ContentService $contentService,
		LocationService $locationService,
		RequestStack $requestStack,
		DownloadService $downloadService,
		MediaService $mediaService,
		RichtextConverterService $richtextConverter,
		CustomSOLRService $solr,
		LanguageService $languageService

	) {
		$this->downloadService = $downloadService;
		$this->mediaService      = $mediaService;
		$this->productService = $productService;
		$this->translator        = $translator;
		$this->contentService    = $contentService;
		$this->locationService    = $locationService;
		$this->request = $requestStack->getCurrentRequest();
		$this->router          = $router;
		$this->richtextConverter = $richtextConverter;
		$this->solr = $solr;
		$this->languageService = $languageService;
	}


	private function queryProductByProductNumber($productNumber)
	{
		$productNumber = new \Ibexa\Contracts\ProductCatalog\Values\Product\Query\Criterion\ProductCode([$productNumber]);
		$productQuery = new ProductQuery(null, $productNumber, []);
		$products = $this->productService->findProducts($productQuery);
		foreach ($products->getProducts() as $product) {
			return $product;
		}
		return null;
	}

	private function getDetailsOfProduct($product, $onlyHref = false)
	{

		$productDetails = [
			"discontinued" => [],
			"product" => []
		];
		$productDataFields = $product->getContent();
		$productDetailPageIds = $productDataFields->getFieldValue("product_relations")->destinationContentIds;
		if (empty($productDetailPageIds)) {
			//No detailpage set
			return false;
		}
		$contentItem = $this->contentService->loadContent($productDetailPageIds[0]);
		$productLocations = $this->locationService->loadLocations($contentItem->getVersionInfo()->getContentInfo());

		if ($productDataFields->getFieldValue("product_discontinued") && $productDataFields->getFieldValue("product_discontinued")->bool == true) {
			if (!isset($productLocations[1])) {
				//No discontinued location found
				return false;
			}
			$discontinuedLink = $this->router->generate('ibexa.url.alias', ['locationId' => $productLocations[1]->id, UrlGeneratorInterface::ABSOLUTE_URL]);

			$productDetails["discontinued"]["link"] = $discontinuedLink;
			$productDetails["discontinued"]["pageId"] = $productDetailPageIds[0];

			if (!$onlyHref) {
				$image = $this->mediaService->getImage($productDataFields, "image", "ProductContentTeaser");
				$productDetails["discontinued"]["pageId"] = $productDetailPageIds[0];
				$productDetails["discontinued"]["image"] = $image;
				$productDetails["discontinued"]["headline"] = $contentItem->getFieldValue("title");
				$productDetails["discontinued"]["copytext"] = $contentItem->getFieldValue("subtitle");
			}
		} else {
			$productDetails["product"]["link"] =  $this->router->generate('ibexa.url.alias', ['locationId' => $contentItem->getVersionInfo()->getContentInfo()->mainLocationId, UrlGeneratorInterface::ABSOLUTE_URL]);
			$productDetails["product"]["pageId"] = $productDetailPageIds[0];

			if (!$onlyHref) {
				$image = $this->mediaService->getImage($productDataFields, "image", "ProductContentTeaser");
				$productDetails["product"]["pageId"] = $productDetailPageIds[0];
				$productDetails["product"]["image"] = $image;
				$productDetails["product"]["headline"] = $contentItem->getFieldValue("title");
				$productDetails["product"]["copytext"] = $contentItem->getFieldValue("subtitle");
			}
		}
		return $productDetails;
	}


	private function findProductByProductNumber($querystring)
	{
		$productDetails = [];
		try {
			if (strlen($querystring) >= 8 and is_numeric($querystring)) {
				$product = $this->queryProductByProductNumber($querystring);
				if ($product) {
					$productType = $product->getProductType()->getIdentifier();
					if ($productType === "replacement_parts") {
						$productDetails["product"]["link"] = $this->translator->trans('product_type.sparepart_detail_url', [], 'products') . "/" . $product->getCode();
					} else {
						$productDetails = $this->getDetailsOfProduct($product, true);
					}
				} else {
					$replacementpart = $this->solr->getReplacementpartByProductnumber($querystring);
					$language = $this->languageService->getCurrentRequestLanguage();

					if (array_key_exists($language, $replacementpart)) {
						$productDetails["product"]["link"] = $this->translator->trans('product_type.sparepart_detail_url', [], 'products') . "/" . $querystring;
					}
				}
			}
		} catch (\Exception $exception) {
			return $productDetails;
		}
		return $productDetails;
	}

	private function findContentByQueryString($querystring)
	{
		//TODO: Implement this function
		return null;
	}

	private function findDownloads($querystring)
	{
		$downloads = [];

		$limit = 15;
		$page = 1;

		$offset = ($page - 1) * $limit;

		$downloads = [];

		if (!empty($querystring)) {
			$result = $this->downloadService->findDownloads($querystring, [], $limit, $offset);
			$resultIds = [];
			foreach ($result->searchHits as $searchHit) {
				/** @var Content */
				$content = $searchHit->valueObject;
				$resultIds[] = $content->id;
			}
			$downloads = $this->downloadService->collectDownloads($resultIds);
		}

		return [
			"api_url" => $this->router->generate('app.search.downloads', [
				"q" => $querystring
			], UrlGeneratorInterface::ABSOLUTE_URL),
			"items_per_page" => 15,
			"total" => $result->totalCount ?? 0,
			"downloads" => $downloads
		];
	}

	private function findProductPagesByProductName($querystring)
	{
		$foundProducts = [
			"discontinued" => [],
			"product" => []
		];
		$isQueryStringSorroundedByQuotationMarks = preg_match('/^(["\']).*\1$/m', $querystring);

		if ($isQueryStringSorroundedByQuotationMarks) {
			$querystringWithtoutQuotes =  str_replace('"', '', $querystring);
			$productName = new \Ibexa\Contracts\ProductCatalog\Values\Product\Query\Criterion\ProductName('*' . $querystringWithtoutQuotes . '*');
			$productQuery = new ProductQuery(null, $productName, []);
		} else {
			$querystringWithJustLettersAndSymbols = preg_replace("/[^a-zA-Z0-9]+/", " ", $querystring);
			$querystringWithJustLettersSymbolsWithoutWhiteSpaceAtTheEnd = rtrim($querystringWithJustLettersAndSymbols);
			$keywords = explode(" ", $querystringWithJustLettersSymbolsWithoutWhiteSpaceAtTheEnd);
			$keywordNames = [];
			foreach ($keywords as $keyword) {
				$keywordNames[] = new \Ibexa\Contracts\ProductCatalog\Values\Product\Query\Criterion\ProductName('*' . $keyword . '*');
			}
			$productNames = new \Ibexa\Contracts\ProductCatalog\Values\Product\Query\Criterion\LogicalOr($keywordNames);
			$productQuery = new ProductQuery(null, $productNames, []);
		}

		$products = $this->productService->findProducts($productQuery);

		foreach ($products->getProducts() as $product) {
			// have every productpage only once in the result list
			$productType = $product->getProductType()->getIdentifier();
			if ($productType === "replacement_parts") {
				$productContent = $product->getContent();
				$image = [];
				$media = $productContent->getFieldValue('sparepart_media');
				if ($media && isset($media->destinationContentIds[0])) {
					$mediaContent = $this->mediaService->getRelatedMedia($media->destinationContentIds[0], "ProductContentTeaser");
					$image = $mediaContent["data"];
				}
				$foundProducts["product"][$product->getName()]["image"] = $image;
				$foundProducts["product"][$product->getName()]["link"] = $this->translator->trans('product_type.sparepart_detail_url', [], 'products') . "/" . $product->getCode();
				$foundProducts["product"][$product->getName()]["headline"] = $productContent->getFieldValue('name');
				$foundProducts["product"][$product->getName()]["copytext"] = $this->richtextConverter->convert($productContent->getFieldValue('description'));
			} else {
				$productPageData = $this->getDetailsOfProduct($product);
				if ($productPageData && $productPageData["product"]) {
					$foundProducts["product"][$productPageData["product"]["pageId"]] = $productPageData["product"];
				}
				if ($productPageData && $productPageData["discontinued"]) {
					$foundProducts["discontinued"][$productPageData["discontinued"]["pageId"]] = $productPageData["discontinued"];
				}
			}
		}

		$data["product"] = [
			"cards" => $foundProducts["product"]
		];
		$data["discontinued"] = [
			"cards" => $foundProducts["discontinued"]
		];
		return $data;
	}

	public function show()
	{
		$parameters = [];
		$queryString = $this->request->get('q');
		$parameters["products"] = [];

		if ($queryString && $queryString !== "" && $queryString !== "*" && $queryString !== " " && $queryString !== "+" && $queryString !== "-") {
			$productDetails = $this->findProductByProductNumber($queryString);

			if ($productDetails && isset($productDetails["discontinued"]) && array_key_exists("link", $productDetails["discontinued"]) && $productDetails["discontinued"]["link"] !== "") {
				return $this->redirect($productDetails["discontinued"]["link"]);
			} elseif ($productDetails && isset($productDetails["product"]) && array_key_exists("link", $productDetails["product"]) && $productDetails["product"]["link"] !== "") {
				return $this->redirect($productDetails["product"]["link"]);
			} else {
				$allProducts = $this->findProductPagesByProductName($queryString, false);
				$parameters["products"] = $allProducts["product"];
				$parameters["discontinued_products"] = $allProducts["discontinued"];
			}
		}

		$resultsNumber = 0;
		foreach ($parameters as $value) {
			if ($value && $value["cards"]) {
				$resultsNumber += count($value["cards"]);
			}
		}

		$result = $this->translator->trans("search.result_string", [
			"%keyword%" => $queryString,
			"%results%" => $resultsNumber
		], "search");

		$parameters["search_info"] = [
			"introSearch" => [
				"headline_type" => "secondary",
				"headline" => [
					"tag" => "h2",
					"text" => $this->translator->trans('search.headline_search', [], 'search'),
					"accent" => true
				],
				"text" => [
					"size" => "M",
					"content" => $this->translator->trans('search.copytext_search', [], 'search')
				]
			],
			"form" => [
				"action" => "/search",
				"method" => "get",
				"search" => [
					"input" => [
						"name" => "q",
						"value" => $queryString,
						"label" => $this->translator->trans('search.label_search', [], 'search')
					],
					"button_text" => $this->translator->trans('search.input_search', [], 'search')
				]
			],
			"result" => $result
		];


		$noResultsMessage = [
			"template" => "@organisms/TextContainer/TextContainer.twig",
			"data" => [
				"text_content" => [
					"title" => [
						"tag" => "h4",
						"text" => $this->translator->trans('search.no_results', [], 'search'),
						"headline_type" => "quaternary"
					],
				]
			]
		];

		$productsSearchResults = $noResultsMessage;
		if (array_key_exists("products", $parameters) && array_key_exists("cards", $parameters["products"])  && count($parameters["products"]["cards"]) > 0) {
			$productsSearchResults = [
				"template" => "@organisms/ProductContentTeaser/ProductContentTeaser.twig",
				"data" => $parameters["products"],
			];
		} else {
			$parameters["products"] = [
				"cards" => []
			];
		}

		$productsDiscontinuedSearchResults = $noResultsMessage;
		if (array_key_exists("discontinued_products", $parameters) && array_key_exists("cards", $parameters["discontinued_products"]) && count($parameters["discontinued_products"]["cards"]) > 0) {
			$productsDiscontinuedSearchResults = [
				"template" => "@organisms/ProductContentTeaser/ProductContentTeaser.twig",
				"data" => $parameters["discontinued_products"],
			];
		} else {
			$parameters["discontinued_products"] = [
				"cards" => []
			];
		}

		$downloadSearchResults = $noResultsMessage;
		$downloads = $this->findDownloads($queryString);
		if ($downloads["total"] > 0) {
			$downloadSearchResults = [
				"template" => "@organisms/Downloads/Downloads.twig",
				"data" => $downloads,
			];
		}



		$parameters["tabs"] = [
			"intro" => [
				"headline_type" => "secondary",
				"headline" => [
					"tag" => "h2",
					"text" => ""
				]
			],
			"tabs" => [
				[
					"title" => "Products (" . count($parameters["products"]["cards"]) . ")",
					"content" => [$productsSearchResults]
				],
				[
					"title" => "Service für abgelaufene Produkte (" . count($parameters["discontinued_products"]["cards"]) . ")",
					"content" => [$productsDiscontinuedSearchResults]
				],
				[
					"title" => "Downloads (" . $downloads["total"] . ")",
					"content" => [$downloadSearchResults]
				],
				[
					"title" => "More (0)",
					"content" => [$noResultsMessage]
				]
			]
		];

		return $this->render('@ibexadesign/full/search_page.html.twig', [
			"search_info" => $parameters["search_info"],
			"tabs" => $parameters["tabs"]
		]);
	}

	public function index(ContentView $view)
	{
		$parameters = [];
		$queryString = $this->request->get('q');
		if ($queryString && $queryString !== "") {
			$productDetails = $this->findProductByProductNumber($queryString);
			if (array_key_exists("link", $productDetails) && $productDetails["link"] !== "") {
				return $this->redirect($productDetails["link"]);
			} else {

				$allProducts = $this->findProductPagesByProductName($queryString, true);
				$parameters["products"] = $allProducts["products"];

				$parameters["discontinued_products"] = $allProducts["discontinued"];
				$parameters["downloads"] = $this->findDownloads($queryString);
				$parameters["content"] = $this->findContentByQueryString($queryString);
			}
			$view->addParameters($parameters);
		} else {
			// @todo: show search page with search field without results.
		}

		return $view;
	}
}
