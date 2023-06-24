<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Services\LinkService;
use App\Services\MediaService;
use App\Services\RichtextConverterService;

use Ibexa\FieldTypePage\FieldType\Page\Block\Renderer\BlockRenderEvents;
use Ibexa\FieldTypePage\FieldType\Page\Block\Renderer\Event\PreRenderEvent;
use Ibexa\FieldTypePage\FieldType\Page\Block\Renderer\Twig\TwigRenderRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\ContentService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class BoxSubscriber implements EventSubscriberInterface
{
	private MediaService $mediaService;
	private LinkService $linkService;
	private LocationService $locationService;
	private ContentService $contentService;
	private RichtextConverterService $richtextConverter;
	protected RouterInterface $router;

	public function __construct(
		MediaService $mediaService,
		LinkService $linkService,
		ContentService $contentService,
		LocationService $locationService,
		RichtextConverterService $richtextConverter,
		RouterInterface $router
	)
	{
		$this->mediaService = $mediaService;
		$this->linkService = $linkService;
		$this->contentService    = $contentService;
		$this->locationService    = $locationService;
		$this->richtextConverter= $richtextConverter;
		$this->router            = $router;
	}

	public static function getSubscribedEvents(): array
	{
		return [
			BlockRenderEvents::getBlockPreRenderEventName('textcontainer') => 'onBlockPreRender',
		];
	}



  public function onBlockPreRender(PreRenderEvent $event): void
	{
		$renderRequest = $event->getRenderRequest();
		if (!$renderRequest instanceof TwigRenderRequest) {
			return;
		}
		$headline = $event->getBlockValue()->getAttribute('headline')->getValue();
		$accent = $event->getBlockValue()->getAttribute('accent')->getValue();
		$subheadline = $event->getBlockValue()->getAttribute('subheadline')->getValue();
		$description = $this->richtextConverter->convertString($event->getBlockValue()->getAttribute('description')->getValue());
		$grey_background= $event->getBlockValue()->getAttribute('grey_background')->getValue();


		$data= [
			"intro" => [
				"headline_type" => "secondary",
			]
		];

		if (!is_null($headline) && !empty(trim($headline))) {
			$data['intro']['headline']['tag'] = "h2";
			$data['intro']['headline']['text'] = $headline;
		}

		if ($accent) {
			$data['intro']['headline']['accent'] = $accent;
		}

		if ($grey_background) {
			$data['grey_background'] = $grey_background;
		}

		if (!is_null($subheadline) && !empty(trim($subheadline))) {
			$data['intro']['subheadline']['tag'] = "h5";
			$data['intro']['subheadline']['text'] = $subheadline;
		}

		if (!is_null($description) && !empty(trim($description))) {
			$data['intro']['text']['size'] = "M";
			$data['intro']['text']['content'] = $description;
		}

		$renderRequest->setParameters($data);
	}


}
