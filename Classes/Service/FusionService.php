<?php

namespace Garagist\ContentBox\FusionService\Service;

use Garagist\ContentBox\Exception\ContentBoxRenderingException;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Afx\Parser\AfxParserException;
use Neos\Fusion\Afx\Service\AfxService;
use Neos\Fusion\Core\FusionConfiguration;
use Neos\Fusion\Core\FusionSourceCodeCollection;
use Neos\Fusion\Core\Parser;
use Neos\Fusion\Core\RuntimeFactory as FusionRuntimeFactory;
use Neos\Fusion\Exception;
use Neos\Fusion\Exception\RuntimeException;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\FusionSourceCodeFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Class FusionService
 */
class FusionService
{
    use DummyControllerContextTrait;

    /**
     * @Flow\Inject
     * @var FusionRuntimeFactory
     */
    protected $fusionRuntimeFactory;

    /**
     * @Flow\Inject
     * @var Parser
     */
    protected $fusionParser;

    /**
     * @Flow\Inject
     * @var FusionSourceCodeFactory
     */
    protected $fusionSourceCodeFactory;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * Render the given string of AFX and returns it
     *
     * @param array $contextProperties
     * @param string $html
     * @param string|null $props
     * @return string
     * @throws ContentBoxRenderingException|AfxParserException
     */
    public function render(array $contextProperties, string $html, ?string $props = null): string
    {
        $props = isset($props) ? Yaml::parse($props) : [];
        $controllerContext = $this->createDummyControllerContext();

        try {
            $fusion = AfxService::convertAfxToFusion($html);
            $parsedFusion = $this->parseFusionSourceCode('html = ' . $fusion, $contextProperties['site'] ?? null);

            $fusionRuntime = $this->fusionRuntimeFactory->createFromConfiguration($parsedFusion, $controllerContext);
            $fusionRuntime->pushContext('props', $props);

            foreach ($contextProperties as $key => $value) {
                if ($value) {
                    $fusionRuntime->pushContext($key, $value);
                }
            }
            $fusionRuntime->setEnableContentCache(false);
            return $fusionRuntime->render('html');
        } catch (RuntimeException $e) {
            throw new ContentBoxRenderingException($e->getPrevious()->getMessage(), 1600950000, $e);
        } catch (AfxParserException $e) {
            throw new ContentBoxRenderingException($e->getMessage(), 1600960000, $e);
        }
    }

    /**
     * Output an exception as a string in a red colored box
     *
     * @param Exception $exeption
     * @return string
     */
    public function renderException(Exception $exeption): string
    {
        $content = htmlspecialchars(
            (string) $exeption->getMessage(),
            ENT_NOQUOTES | ENT_HTML401,
            ini_get('default_charset'),
            true
        );
        $open =
            '<span style="font-family: monospace; max-height: none; font-size: 1rem; width: 100%; margin: 30px auto; color: #fff; background: #d9534f; box-shadow: 0 1px 10px rgba(0,0,0,0.1); padding: 5% 12px; display: block; height: auto;">';
        $close = '</span>';
        return $open . $content . $close;
    }

    private function parseFusionSourceCode(string $fusionSourceCode, ?TraversableNodeInterface $currentSiteNode): FusionConfiguration
    {
        return $this->fusionParser->parseFromSource(
            $this->tryFusionCodeCollectionFromSiteNode($currentSiteNode)
                ->union(
                    $this->fusionSourceCodeFactory->createFromNodeTypeDefinitions()
                )
                ->union(
                    $this->fusionSourceCodeFactory->createFromAutoIncludes()
                )
                ->union(
                    FusionSourceCodeCollection::fromFilePath('resource://Garagist.ContentBox/Private/ContentBox/Root.fusion')
                )
                ->union(
                    FusionSourceCodeCollection::fromString($fusionSourceCode)
                )
        );
    }

    private function tryFusionCodeCollectionFromSiteNode(?TraversableNodeInterface $siteNode): FusionSourceCodeCollection
    {
        $site = null;
        if ($siteNode) {
            $site = $this->siteRepository->findOneByNodeName((string)$siteNode->getNodeName())
                ?? throw new \Neos\Neos\Domain\Exception(sprintf('No site found for nodeNodeName "%s"', $siteNode->getNodeName()), 1677245517);
        }
        return $site
            ? $this->fusionSourceCodeFactory->createFromSite($site)
            : FusionSourceCodeCollection::empty();
    }
}
