<?php

namespace TNW\Idealdata\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Introduction block on Stores → Configuration → IDEALDATA.IO → Integration.
 *
 * Renders static marketing copy plus the two CTAs. The CTA URLs are built here
 * rather than hard-coded in the template because they carry attribution
 * parameters (see buildCtaUrl()).
 */
class Intro extends Field
{
    protected $_template = 'TNW_Idealdata::system/config/intro.phtml';

    /**
     * Trial signup destination (IdealData app).
     */
    private const TRIAL_URL = 'https://my.idealdata.io';

    /**
     * Guided-setup booking destination (HubSpot meetings).
     */
    private const WALKTHROUGH_URL = 'https://idealdata.io/meetings/igorek12345/idealdata-walkthrough';

    /**
     * Fixed attribution values, shared by both CTAs. These are a reporting
     * contract with GA4 / HubSpot — changing a value fragments the historical
     * data, so treat them as append-only.
     */
    private const UTM_SOURCE = 'magento_admin';
    private const UTM_MEDIUM = 'extension';
    private const UTM_CAMPAIGN = 'ac_module_intro';

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var BackendUrlInterface
     */
    private $backendUrl;

    public function __construct(
        Context $context,
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        BackendUrlInterface $backendUrl,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->productMetadata = $productMetadata;
        $this->moduleList = $moduleList;
        $this->backendUrl = $backendUrl;
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * "Start your 15-day free trial" CTA.
     */
    public function getTrialUrl(): string
    {
        return $this->buildCtaUrl(self::TRIAL_URL, 'trial');
    }

    /**
     * "Or have someone walk you through it" CTA.
     */
    public function getWalkthroughUrl(): string
    {
        return $this->buildCtaUrl(self::WALKTHROUGH_URL, 'walkthrough');
    }

    /**
     * Append attribution parameters to a CTA destination.
     *
     * - utm_*     — session attribution in GA4 / HubSpot. Fragile: dies on any
     *               redirect that drops the query string.
     * - referrer  — the admin domain this click came from, so a click can be
     *               tied to the account that later signs up. Durable: meant to
     *               be persisted onto the account record, not just the session.
     * - pv / pe   — Magento version and edition, for qualifying the store
     *               before a walkthrough call.
     * - mv        — this module's version, so we can tell which shipped copy of
     *               the page produced the click.
     *
     * Empty values are dropped rather than sent as blanks, so a store that
     * cannot resolve (say) its admin domain still gets clean UTMs.
     */
    private function buildCtaUrl(string $baseUrl, string $utmContent): string
    {
        $params = array_filter(
            [
                'utm_source' => self::UTM_SOURCE,
                'utm_medium' => self::UTM_MEDIUM,
                'utm_campaign' => self::UTM_CAMPAIGN,
                'utm_content' => $utmContent,
                'referrer' => $this->getAdminDomain(),
                'pv' => $this->getPlatformVersion(),
                'pe' => $this->getPlatformEdition(),
                'mv' => $this->getModuleVersion(),
            ],
            static function ($value) {
                return $value !== '' && $value !== null;
            }
        );

        $separator = strpos($baseUrl, '?') === false ? '?' : '&';

        return $baseUrl . $separator . http_build_query($params);
    }

    /**
     * Host of the admin this page is being served from — e.g. "admin.example.com"
     * on a store with a custom admin URL, or "example.com" where the admin
     * shares the storefront domain.
     *
     * Prefers the CONFIGURED admin base URL over the request's Host header: it
     * is stable regardless of how an operator reached the admin, and it is not
     * client-controlled. Falls back to the request host if the base URL cannot
     * be parsed.
     */
    private function getAdminDomain(): string
    {
        $host = '';

        try {
            $host = (string) parse_url($this->backendUrl->getBaseUrl(), PHP_URL_HOST);
        } catch (\Exception $e) {
            $host = '';
        }

        if ($host === '') {
            // getHttpHost() can carry a port ("example.com:8443"); strip it.
            $host = preg_replace('/:\d+$/', '', (string) $this->getRequest()->getHttpHost());
        }

        return strtolower(trim((string) $host));
    }

    /**
     * Magento/Adobe Commerce version, e.g. "2.4.7-p3". Composer-based installs
     * can report "UNKNOWN"; that is not worth sending.
     */
    private function getPlatformVersion(): string
    {
        $version = trim((string) $this->productMetadata->getVersion());

        return strcasecmp($version, 'UNKNOWN') === 0 ? '' : $version;
    }

    /**
     * Magento/Adobe Commerce edition, lowercased — "community", "enterprise", "b2b".
     */
    private function getPlatformEdition(): string
    {
        return strtolower(trim((string) $this->productMetadata->getEdition()));
    }

    /**
     * This module's setup_version from etc/module.xml, e.g. "1.12.0".
     */
    private function getModuleVersion(): string
    {
        $module = $this->moduleList->getOne('TNW_Idealdata');

        return trim((string) ($module['setup_version'] ?? ''));
    }
}
