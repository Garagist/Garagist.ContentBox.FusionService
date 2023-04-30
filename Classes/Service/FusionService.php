<?php

namespace Garagist\ContentBox\FusionService\Service;

use Garagist\ContentBox\Exception\ContentBoxRenderingException;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Afx\Parser\AfxParserException;
use Neos\Fusion\Afx\Service\AfxService;
use Neos\Fusion\Core\Parser;
use Neos\Fusion\Core\RuntimeFactory as FusionRuntimeFactory;
use Neos\Fusion\Exception\RuntimeException;
use Neos\Neos\Domain\Service\FusionService as NeosFusionService;
use Symfony\Component\Yaml\Yaml;

/**
 * Class FusionService
 */
class FusionService extends NeosFusionService
{
    use DummyControllerContextTrait;

    /**
     * @Flow\InjectConfiguration(path="fusion.autoInclude", package="Neos.Neos")
     * @var array
     */
    protected $autoIncludeConfiguration = array();

    /**
     * @Flow\Inject
     * @var FusionRuntimeFactory
     */
    protected $fusionRuntimeFactory;

    /**
     * Render the given string of AFX and returns it
     *
     * @param [NodeInterface] $contextNodes
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
            $parsedFusion = $this->getMergedFusionObjectTree('html = ' . $fusion, $contextNodes['site'] ?? null);

            $fusionRuntime = $this->fusionRuntimeFactory->create($parsedFusion, $controllerContext);
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

    /**
     * @Flow\Inject
     * @var Parser
     */
    protected $fusionParser;

    /**
     * Parse all the fusion files the are in the current fusionPathPatterns
     *
     * @param $fusion
     * @param TraversableNodeInterface $startNode
     * @return array
     */
    public function getMergedFusionObjectTree($fusion, ?TraversableNodeInterface $startNode = null): array
    {
        $siteRootFusionCode = '';
        if ($startNode) {
            $siteResourcesPackageKey = $this->getSiteForSiteNode($startNode)->getSiteResourcesPackageKey();
            $siteRootFusionPathAndFilename = sprintf('resource://%s/Private/Fusion/Root.fusion', $siteResourcesPackageKey);
            $siteRootFusionCode = $this->getFusionIncludes([$siteRootFusionPathAndFilename]);
        }

        $fusionCode = $this->generateNodeTypeDefinitions();
        $fusionCode .= $this->getFusionIncludes($this->prepareAutoIncludeFusion());
        $fusionCode .= $this->getFusionIncludes($this->prependFusionIncludes);
        $fusionCode .= $siteRootFusionCode;
        $fusionCode .= $this->getFusionIncludes($this->appendFusionIncludes);
        $fusionCode .= $this->getFusionIncludes(['resource://Garagist.ContentBox/Private/ContentBox/Root.fusion']);
        $fusionCode .= $fusion;

        return $this->fusionParser->parse($fusionCode, null);
    }
}
