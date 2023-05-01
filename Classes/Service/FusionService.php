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
     * @param TraversableNodeInterface[] $contextNodes
     * @param string $html
     * @param string|null $props
     * @return string
     * @throws ContentBoxRenderingException|AfxParserException
     */
    public function render(array $contextNodes, string $html, ?string $props = null): string
    {
        $props = isset($props) ? Yaml::parse($props) : [];
        $controllerContext = $this->createDummyControllerContext();

        try {
            $fusion = AfxService::convertAfxToFusion($html);
            $parsedFusion = $this->parseFusionSourceCode('html = ' . $fusion, $contextNodes['site'] ?? null);

            $fusionRuntime = $this->fusionRuntimeFactory->createFromConfiguration($parsedFusion, $controllerContext);
            $fusionRuntime->pushContext('props', $props);
            if (isset($contextNodes['node'])) {
                $fusionRuntime->pushContext('node', $contextNodes['node']);
            }
            if (isset($contextNodes['documentNode'])) {
                $fusionRuntime->pushContext('documentNode', $contextNodes['documentNode']);
            }
            if (isset($contextNodes['site'])) {
                $fusionRuntime->pushContext('site', $contextNodes['site']);
            }
            $fusionRuntime->setEnableContentCache(false);
            return $fusionRuntime->render('html');
        } catch (RuntimeException $e) {
            throw new ContentBoxRenderingException($e->getPrevious()->getMessage(), 1600950000, $e);
        } catch (AfxParserException $e) {
            throw new ContentBoxRenderingException($e->getMessage(), 1600960000, $e);
        }
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
