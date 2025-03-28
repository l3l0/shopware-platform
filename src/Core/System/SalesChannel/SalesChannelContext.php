<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel;

use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Struct\StateAwareTrait;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Exception\ContextPermissionsLockedException;
use Shopware\Core\System\SalesChannel\Exception\ContextRulesLockedException;
use Shopware\Core\System\Tax\Exception\TaxNotFoundException;
use Shopware\Core\System\Tax\TaxCollection;

class SalesChannelContext extends Struct
{
    use StateAwareTrait;

    /**
     * Unique token for context, e.g. stored in session or provided in request headers
     *
     * @var string
     */
    protected $token;

    /**
     * @var CustomerGroupEntity
     */
    protected $currentCustomerGroup;

    /**
     * @var CustomerGroupEntity
     */
    protected $fallbackCustomerGroup;

    /**
     * @var CurrencyEntity
     */
    protected $currency;

    /**
     * @var SalesChannelEntity
     */
    protected $salesChannel;

    /**
     * @var TaxCollection
     */
    protected $taxRules;

    /**
     * @var CustomerEntity|null
     */
    protected $customer;

    /**
     * @var PaymentMethodEntity
     */
    protected $paymentMethod;

    /**
     * @var ShippingMethodEntity
     */
    protected $shippingMethod;

    /**
     * @var ShippingLocation
     */
    protected $shippingLocation;

    /**
     * @var array
     */
    protected $rulesIds;

    /**
     * @var bool
     */
    protected $rulesLocked = false;

    /**
     * @var array
     */
    protected $permissions = [];

    /**
     * @var bool
     */
    protected $permisionsLocked = false;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var CashRoundingConfig
     */
    private $itemRounding;

    /**
     * @var CashRoundingConfig
     */
    private $totalRounding;

    /**
     * @var string|null
     */
    private $domainId;

    /**
     * @deprecated tag:v6.5.0 - __construct will be internal, use context factory to create a new context
     * @deprecated tag:v6.5.0 - Parameter $fallbackCustomerGroup is deprecated and will be removed
     *
     * @param array<string> $rulesIds
     */
    public function __construct(
        Context $baseContext,
        string $token,
        ?string $domainId,
        SalesChannelEntity $salesChannel,
        CurrencyEntity $currency,
        CustomerGroupEntity $currentCustomerGroup,
        CustomerGroupEntity $fallbackCustomerGroup,
        TaxCollection $taxRules,
        PaymentMethodEntity $paymentMethod,
        ShippingMethodEntity $shippingMethod,
        ShippingLocation $shippingLocation,
        ?CustomerEntity $customer,
        CashRoundingConfig $itemRounding,
        CashRoundingConfig $totalRounding,
        array $rulesIds = []
    ) {
        $this->currentCustomerGroup = $currentCustomerGroup;
        $this->fallbackCustomerGroup = $fallbackCustomerGroup;
        $this->currency = $currency;
        $this->salesChannel = $salesChannel;
        $this->taxRules = $taxRules;
        $this->customer = $customer;
        $this->paymentMethod = $paymentMethod;
        $this->shippingMethod = $shippingMethod;
        $this->shippingLocation = $shippingLocation;
        $this->rulesIds = $rulesIds;
        $this->token = $token;
        $this->context = $baseContext;
        $this->itemRounding = $itemRounding;
        $this->totalRounding = $totalRounding;
        $this->domainId = $domainId;
    }

    public function getCurrentCustomerGroup(): CustomerGroupEntity
    {
        return $this->currentCustomerGroup;
    }

    /**
     * @deprecated tag:v6.5.0 - Fallback customer group is deprecated and will be removed, use getCurrentCustomerGroup instead
     */
    public function getFallbackCustomerGroup(): CustomerGroupEntity
    {
        Feature::triggerDeprecationOrThrow(
            'v6.5.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.5.0.0', 'getCurrentCustomerGroup()')
        );

        return $this->fallbackCustomerGroup;
    }

    public function getCurrency(): CurrencyEntity
    {
        return $this->currency;
    }

    public function getSalesChannel(): SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function getTaxRules(): TaxCollection
    {
        return $this->taxRules;
    }

    /**
     * Get the tax rules depend on the customer billing address
     * respectively the shippingLocation if there is no customer
     */
    public function buildTaxRules(string $taxId): TaxRuleCollection
    {
        $tax = $this->taxRules->get($taxId);

        if ($tax === null || $tax->getRules() === null) {
            throw new TaxNotFoundException($taxId);
        }

        if ($tax->getRules()->first() !== null) {
            // NEXT-21735 - This is covered randomly
            // @codeCoverageIgnoreStart
            return new TaxRuleCollection([
                new TaxRule($tax->getRules()->first()->getTaxRate(), 100),
            ]);
            // @codeCoverageIgnoreEnd
        }

        return new TaxRuleCollection([
            new TaxRule($tax->getTaxRate(), 100),
        ]);
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function getPaymentMethod(): PaymentMethodEntity
    {
        return $this->paymentMethod;
    }

    public function getShippingMethod(): ShippingMethodEntity
    {
        return $this->shippingMethod;
    }

    public function getShippingLocation(): ShippingLocation
    {
        return $this->shippingLocation;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getRuleIds(): array
    {
        return $this->rulesIds;
    }

    /**
     * @param array<string> $ruleIds
     */
    public function setRuleIds(array $ruleIds): void
    {
        if ($this->rulesLocked) {
            throw new ContextRulesLockedException();
        }

        $this->rulesIds = array_filter(array_values($ruleIds));
        $this->getContext()->setRuleIds($this->rulesIds);
    }

    public function lockRules(): void
    {
        $this->rulesLocked = true;
    }

    public function lockPermissions(): void
    {
        $this->permisionsLocked = true;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getTaxState(): string
    {
        return $this->context->getTaxState();
    }

    public function setTaxState(string $taxState): void
    {
        $this->context->setTaxState($taxState);
    }

    public function getTaxCalculationType(): string
    {
        return $this->getSalesChannel()->getTaxCalculationType();
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): void
    {
        if ($this->permisionsLocked) {
            throw new ContextPermissionsLockedException();
        }

        $this->permissions = array_filter($permissions);
    }

    public function getApiAlias(): string
    {
        return 'sales_channel_context';
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions[$permission] ?? false;
    }

    public function getSalesChannelId(): string
    {
        return $this->getSalesChannel()->getId();
    }

    public function addState(string ...$states): void
    {
        $this->context->addState(...$states);
    }

    public function removeState(string $state): void
    {
        $this->context->removeState($state);
    }

    public function hasState(string ...$states): bool
    {
        return $this->context->hasState(...$states);
    }

    public function getStates(): array
    {
        return $this->context->getStates();
    }

    public function getDomainId(): ?string
    {
        return $this->domainId;
    }

    public function setDomainId(?string $domainId): void
    {
        $this->domainId = $domainId;
    }

    public function getLanguageIdChain(): array
    {
        return $this->context->getLanguageIdChain();
    }

    public function getLanguageId(): string
    {
        return $this->context->getLanguageId();
    }

    public function getVersionId(): string
    {
        return $this->context->getVersionId();
    }

    public function considerInheritance(): bool
    {
        return $this->context->considerInheritance();
    }

    public function getTotalRounding(): CashRoundingConfig
    {
        return $this->totalRounding;
    }

    public function setTotalRounding(CashRoundingConfig $totalRounding): void
    {
        $this->totalRounding = $totalRounding;
    }

    public function getItemRounding(): CashRoundingConfig
    {
        return $this->itemRounding;
    }

    public function setItemRounding(CashRoundingConfig $itemRounding): void
    {
        $this->itemRounding = $itemRounding;
    }

    public function getCurrencyId(): string
    {
        return $this->getCurrency()->getId();
    }

    public function ensureLoggedIn(bool $allowGuest = true): void
    {
        if ($this->customer === null) {
            throw new CustomerNotLoggedInException();
        }

        if (!$allowGuest && $this->customer->getGuest()) {
            throw new CustomerNotLoggedInException();
        }
    }

    public function getCustomerId(): ?string
    {
        return $this->customer ? $this->customer->getId() : null;
    }
}
