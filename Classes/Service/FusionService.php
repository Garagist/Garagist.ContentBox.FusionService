<?php

namespace Garagist\ContentBox\FusionService\Service;

use Garagist\ContentBox\Exception\ContentBoxRenderingException;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Afx\Parser\AfxParserException;
use Neos\Fusion\Afx\Service\AfxService;
use Neos\Fusion\Core\Parser;
use Neos\Fusion\Core\RuntimeFactory as FusionRuntimeFactory;
use Neos\Fusion\Exception;
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
            $parsedFusion = $this->getMergedFusionObjectTree('html = ' . $fusion, $contextProperties['site'] ?? null);

            $fusionRuntime = $this->fusionRuntimeFactory->create($parsedFusion, $controllerContext);
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
