import template from './sw-order-detail-base.html.twig';

const { Component, Utils, Mixin } = Shopware;
const { EntityCollection, Criteria } = Shopware.Data;
const { get, format, array } = Utils;

/**
 * @feature-deprecated (flag:FEATURE_NEXT_7530) will be dropped
 */
Component.register('sw-order-detail-base', {
    template,

    inject: [
        'repositoryFactory',
        'orderService',
        'stateStyleDataProviderService',
        'acl',
        'feature',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        orderId: {
            type: String,
            required: true,
        },

        isLoading: {
            type: Boolean,
            required: true,
        },

        isEditing: {
            type: Boolean,
            required: true,
        },

        isSaveSuccessful: {
            type: Boolean,
            required: true,
        },
    },

    data() {
        return {
            order: null,
            nextRoute: null,
            isDisplayingLeavePageWarning: false,
            transactionOptions: [],
            orderOptions: [],
            deliveryOptions: [],
            versionContext: null,
            customFieldSets: [],
            promotions: [],
            promotionError: null,
            missingProductLineItems: [],
            convertedProductLineItems: [],
            originLineItems: [],
        };
    },

    beforeRouteLeave(to, from, next) {
        if (this.isEditing) {
            this.nextRoute = next;
            this.isDisplayingLeavePageWarning = true;
        } else {
            next();
        }
    },

    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        orderLineItemRepository() {
            return this.repositoryFactory.create('order_line_item');
        },

        delivery() {
            return this.order.deliveries[0];
        },

        deliveryDiscounts() {
            return array.slice(this.order.deliveries, 1) || [];
        },

        transaction() {
            for (let i = 0; i < this.order.transactions.length; i += 1) {
                if (!['cancelled', 'failed'].includes(this.order.transactions[i].stateMachineState.technicalName)) {
                    return this.order.transactions[i];
                }
            }
            return this.order.transactions.last();
        },

        shippingCostsDetail() {
            const calcTaxes = this.sortByTaxRate(this.order.shippingCosts.calculatedTaxes);
            const formattedTaxes = `${calcTaxes.map(
                calcTax => `${this.$tc('sw-order.detailBase.shippingCostsTax', 0, {
                    taxRate: calcTax.taxRate,
                    tax: format.currency(calcTax.tax, this.order.currency.shortName),
                })}`,
            ).join('<br>')}`;

            return `${this.$tc('sw-order.detailBase.tax')}<br>${formattedTaxes}`;
        },

        sortedCalculatedTaxes() {
            return this.sortByTaxRate(this.order.price.calculatedTaxes).filter(price => price.tax !== 0);
        },

        transactionOptionPlaceholder() {
            const headline = this.$tc('sw-order.stateCard.headlineTransactionState');
            if (this.isLoading) {
                return null;
            }

            return `${headline}: ${this.transaction.stateMachineState.translated.name}`;
        },

        transactionOptionsBackground() {
            if (this.isLoading) {
                return null;
            }

            return this.stateStyleDataProviderService.getStyle('order_transaction.state',
                this.transaction.stateMachineState.technicalName).selectBackgroundStyle;
        },

        orderOptionPlaceholder() {
            const headline = this.$tc('sw-order.stateCard.headlineOrderState');
            if (this.isLoading) {
                return null;
            }

            return `${headline}: ${this.order.stateMachineState.translated.name}`;
        },

        orderOptionsBackground() {
            if (this.isLoading) {
                return null;
            }

            return this.stateStyleDataProviderService.getStyle('order.state',
                this.order.stateMachineState.technicalName).selectBackgroundStyle;
        },

        deliveryOptionPlaceholder() {
            const headline = this.$tc('sw-order.stateCard.headlineDeliveryState');
            if (this.isLoading) {
                return null;
            }

            return `${headline}: ${this.delivery.stateMachineState.translated.name}`;
        },

        deliveryOptionsBackground() {
            if (this.isLoading) {
                return null;
            }

            return this.stateStyleDataProviderService.getStyle('order_delivery.state',
                this.delivery.stateMachineState.technicalName).selectBackgroundStyle;
        },

        orderCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria
                .addAssociation('currency')
                .addAssociation('orderCustomer')
                .addAssociation('language');

            criteria
                .getAssociation('lineItems')
                .addFilter(Criteria.equals('parentId', null))
                .addSorting(Criteria.sort('position', 'ASC'));

            if (this.acl.can('order.editor')) {
                criteria.addAssociation('lineItems.product');
            }

            criteria
                .getAssociation('lineItems.children')
                .addSorting(Criteria.naturalSorting('label'));

            criteria
                .getAssociation('deliveries')
                .addSorting(Criteria.sort('shippingCosts.unitPrice', 'DESC'));

            criteria
                .addAssociation('salesChannel');

            criteria
                .addAssociation('addresses.country')
                .addAssociation('addresses.salutation')
                .addAssociation('addresses.countryState')
                .addAssociation('deliveries.shippingMethod')
                .addAssociation('deliveries.shippingOrderAddress')
                .addAssociation('transactions.paymentMethod')
                .addAssociation('documents.documentType')
                .addAssociation('tags');

            criteria.getAssociation('transactions').addSorting(Criteria.sort('createdAt'));

            return criteria;
        },

        customFieldSetRepository() {
            return this.repositoryFactory.create('custom_field_set');
        },

        customFieldSetCriteria() {
            const criteria = new Criteria(1, null);
            criteria.addFilter(Criteria.equals('relations.entityName', 'order'));

            return criteria;
        },

        disabledAutoPromotionVisibility: {
            get() {
                return !this.hasAutomaticPromotions;
            },
            set(state) {
                this.toggleAutomaticPromotions(state);
            },
        },

        taxStatus() {
            return this.order.price.taxStatus;
        },

        displayRounded() {
            return this.order.totalRounding.interval !== 0.01
                || this.order.totalRounding.decimals !== this.order.itemRounding.decimals;
        },

        orderTotal() {
            if (this.displayRounded) {
                return this.order.price.rawTotal;
            }

            return this.order.price.totalPrice;
        },

        hasLineItem() {
            return this.order.lineItems.filter(item => item.hasOwnProperty('id')).length > 0;
        },

        currency() {
            return this.order.currency;
        },

        promotionCodeLineItems() {
            return this.order.lineItems.filter(item => item.type === 'promotion' && get(item, 'payload.code'));
        },

        hasAutomaticPromotions() {
            return this.order.lineItems.filter(item => item.type === 'promotion' && item.referencedId === null).length > 0;
        },

        promotionCodeTags: {
            get() {
                return this.promotionCodeLineItems.map(p => p.payload);
            },

            set(promotionCodeTags) {
                const old = this.promotionCodeLineItems.map(p => p.payload);

                this.handlePromotionCodeTags(promotionCodeTags, old);
            },
        },
    },

    watch: {
        orderId() {
            this.createdComponent();
        },

        'order.orderNumber'() {
            this.emitIdentifier();
        },

        'order.createdById'() {
            this.emitCreatedById();
        },

        'order.lineItems': {
            deep: true,
            handler: 'updatePromotionList',
        },
    },

    created() {
        this.createdComponent();
    },

    destroyed() {
        this.destroyedComponent();
    },

    methods: {
        createdComponent() {
            this.versionContext = Shopware.Context.api;
            this.reloadEntityData();

            this.$root.$on('language-change', this.reloadEntityData);
            this.$root.$on('order-edit-start', this.onStartEditing);
            this.$root.$on('order-edit-save', this.onSaveEdits);
            this.$root.$on('order-edit-cancel', this.onCancelEditing);

            this.customFieldSetRepository.search(this.customFieldSetCriteria).then((result) => {
                this.customFieldSets = result;
            });
        },

        destroyedComponent() {
            this.$root.$off('language-change', this.reloadEntityData);
            this.$root.$off('order-edit-start', this.onStartEditing);
            this.$root.$off('order-edit-save', this.onSaveEdits);
            this.$root.$off('order-edit-cancel', this.onCancelEditing);
        },

        reloadEntityData() {
            this.$emit('loading-change', true);
            this.orderOptions = [];
            this.transactionOptions = [];
            this.deliveryOptions = [];

            return this.orderRepository.get(this.orderId, this.versionContext, this.orderCriteria).then((response) => {
                this.order = response;
                this.cloneLineItems();
                this.$emit('loading-change', false);
                return Promise.resolve();
            }).catch(() => {
                this.$emit('loading-change', false);
                return Promise.reject();
            });
        },

        cloneLineItems() {
            const originLineItems = new EntityCollection(
                this.orderLineItemRepository.route,
                this.orderLineItemRepository.entityName,
                this.versionContext,
            );

            this.order.lineItems.forEach(lineItem => {
                const lineItemClone = this.orderLineItemRepository.create();
                Object.entries(lineItem).forEach(([key]) => {
                    lineItemClone[key] = lineItem[key];
                });
                originLineItems.add(lineItemClone);
            });

            this.originLineItems = originLineItems;
        },

        emitIdentifier() {
            const orderNumber = this.order !== null ? this.order.orderNumber : '';
            this.$emit('identifier-change', orderNumber);
        },

        emitCreatedById() {
            const createdById = this.order !== null ? this.order.createdById : '';
            this.$emit('created-by-id-change', createdById);
        },

        saveAndReload() {
            this.$emit('loading-change', true);
            return this.orderRepository.save(this.order, this.versionContext).then(() => {
                return this.reloadEntityData();
            }).catch((error) => {
                this.$emit('error', error);
            }).finally(() => {
                this.$emit('loading-change', false);
                return Promise.resolve();
            });
        },

        recalculateAndReload() {
            this.$emit('loading-change', true);
            return this.orderService.recalculateOrder(this.orderId, this.versionContext.versionId, {}, {}).then(() => {
                return this.reloadEntityData();
            }).catch((error) => {
                this.$emit('error', error);
            }).finally(() => {
                this.$emit('loading-change', false);
                return Promise.resolve();
            });
        },

        saveAndRecalculate() {
            this.$emit('loading-change', true);
            return this.orderRepository.save(this.order, this.versionContext).then(() => {
                return this.orderService.recalculateOrder(this.orderId, this.versionContext.versionId, {}, {});
            }).then(() => {
                return this.reloadEntityData();
            }).catch((error) => {
                this.$emit('error', error);
            })
                .finally(() => {
                    this.$emit('loading-change', false);
                    return Promise.resolve();
                });
        },

        onStartEditing() {
            this.$emit('loading-change', true);

            this.orderRepository.createVersion(this.orderId, this.versionContext).then((newContext) => {
                this.versionContext = newContext;
                return this.reloadEntityData();
            }).then(() => {
                return this.convertMissingProductLineItems();
            }).then(() => {
                this.$emit('editing-change', true);
                return Promise.resolve();
            })
                .finally(() => {
                    this.$emit('loading-change', false);
                });
        },

        onSaveEdits() {
            this.$emit('loading-change', true);
            this.$emit('editing-change', false);
            this.order.lineItems = this.originLineItems;
            this.orderRepository.save(this.order, this.versionContext)
                .then(() => {
                    return this.orderRepository.mergeVersion(this.versionContext.versionId, this.versionContext);
                }).catch((error) => {
                    this.$emit('error', error);
                }).finally(() => {
                    this.versionContext.versionId = Shopware.Context.api.liveVersionId;
                    this.missingProductLineItems = [];
                    this.convertedProductLineItems = [];
                    this.reloadEntityData();
                });
        },

        onCancelEditing() {
            this.$emit('loading-change', true);

            this.orderRepository.deleteVersion(
                this.orderId,
                this.versionContext.versionId,
                this.versionContext,
            ).catch((error) => {
                // This error has no consequences, because we revert to the live version anyways
                this.$emit('error', error);
            });

            this.versionContext.versionId = Shopware.Context.api.liveVersionId;
            this.missingProductLineItems = [];
            this.convertedProductLineItems = [];
            this.reloadEntityData().then(() => {
                this.$emit('editing-change', false);
            });
        },

        onShippingChargeEdited(amount) {
            this.delivery.shippingCosts.unitPrice = amount;
            this.delivery.shippingCosts.totalPrice = amount;
            this.saveAndRecalculate();
        },

        sortByTaxRate(price) {
            return price.sort((prev, current) => {
                return prev.taxRate - current.taxRate;
            });
        },

        onStateTransitionOptionsChanged(stateMachineName, options) {
            if (stateMachineName === 'order.states') {
                this.orderOptions = options;
            } else if (stateMachineName === 'order_transaction.states') {
                this.transactionOptions = options;
            } else if (stateMachineName === 'order_delivery.states') {
                this.deliveryOptions = options;
            }
        },

        onQuickOrderStatusChange(actionName) {
            this.$refs['state-card'].onOrderStateSelected(actionName);
        },

        onQuickTransactionStatusChange(actionName) {
            this.$refs['state-card'].onTransactionStateSelected(actionName);
        },

        onQuickDeliveryStatusChange(actionName) {
            this.$refs['state-card'].onDeliveryStateSelected(actionName);
        },

        onLeaveModalClose() {
            this.nextRoute(false);
            this.nextRoute = null;
            this.isDisplayingLeavePageWarning = false;
        },

        onLeaveModalConfirm() {
            this.isDisplayingLeavePageWarning = false;

            this.$nextTick(() => {
                this.nextRoute();
            });
        },

        async deleteAutomaticPromotion(promotion) {
            await this.orderLineItemRepository
                .delete(promotion.id, this.versionContext)
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('sw-order.detailBase.textPromotionRemoved', 0, {
                            promotion: promotion.label,
                        }),
                    });
                })
                .catch((error) => {
                    this.$emit('error', error);
                });
        },

        async toggleAutomaticPromotions(state) {
            this.$emit('loading-change', true);

            const automaticPromotion = this.order.lineItems
                .find(item => item.type === 'promotion' && item.referencedId === null);

            if (automaticPromotion) {
                await this.deleteAutomaticPromotion(automaticPromotion);
            }

            this.orderService.toggleAutomaticPromotions(
                this.order.id,
                this.order.versionId,
                state,
            ).then((response) => {
                this.handlePromotionResponse(response);

                return this.reloadEntityData();
            }).catch((error) => {
                this.$emit('error', error);
            });
        },

        handlePromotionCodeTags(newValue, oldValue) {
            this.promotionError = null;

            if (newValue.length < oldValue.length) {
                return;
            }

            const promotionCodeLength = newValue.length;
            const latestTag = newValue[promotionCodeLength - 1];

            if (newValue.length > oldValue.length) {
                this.onSubmitCode(latestTag.code);
            }

            if (promotionCodeLength > 0 && latestTag.isInvalid) {
                this.promotionError = { detail: this.$tc('sw-order.createBase.textInvalidPromotionCode') };
            }
        },

        onSubmitCode(code) {
            this.orderService.addPromotionToOrder(
                this.order.id,
                this.order.versionId,
                code,
            ).then((response) => {
                this.$emit('loading-change', true);

                this.handlePromotionResponse(response);

                return this.reloadEntityData();
            }).catch((error) => {
                this.$emit('error', error);
            });
        },

        handlePromotionResponse(response) {
            Object.values(response.data.errors).forEach((value) => {
                switch (value.level) {
                    case 0: {
                        this.createNotificationSuccess({
                            message: value.message,
                        });
                        break;
                    }

                    case 10: {
                        this.createNotificationWarning({
                            message: value.message,
                        });
                        break;
                    }

                    default: {
                        this.createNotificationError({
                            message: value.message,
                        });
                        break;
                    }
                }
            });
        },

        onRemoveExistingCode(item) {
            this.$emit('loading-change', true);

            const lineItem = this.getLineItemByPromotionCode(item.code);

            this.orderLineItemRepository
                .delete(lineItem.id, this.versionContext)
                .then(() => {
                    this.reloadEntityData().then(() => {
                        this.$emit('loading-change', false);
                    });
                })
                .catch((error) => {
                    this.$emit('error', error);
                });
        },

        getLineItemByPromotionCode(code) {
            return this.order.lineItems.find(item => {
                const c = get(item, 'payload.code');
                return item.type === 'promotion' && c === code;
            });
        },

        updatePromotionList() {
            // Update data and isInvalid flag for each item in promotionCodeTags
            this.promotionCodeTags = this.promotionCodeTags.map(tag => {
                const matchedItem = this.promotionCodeLineItems.find(lineItem => lineItem.payload.code === tag.code);

                if (matchedItem) {
                    return { ...matchedItem.payload, isInvalid: false };
                }

                return { ...tag, isInvalid: true };
            });

            // Add new items from promotionCodeLineItems which promotionCodeTags doesn't contain
            this.promotionCodeLineItems.forEach(lineItem => {
                const matchedItem = this.promotionCodeTags.find(tag => tag.code === lineItem.payload.code);

                if (!matchedItem) {
                    this.promotionCodeTags = [...this.promotionCodeTags, { ...lineItem.payload, isInvalid: false }];
                }
            });
        },

        convertMissingProductLineItems() {
            this.convertedProductLineItems = this.order.lineItems.filter((lineItem) => {
                return (lineItem.payload.isConvertedProductLineItem && lineItem.type === 'custom');
            });

            this.missingProductLineItems = this.order.lineItems.filter((lineItem) => {
                return (!lineItem.hasOwnProperty('product') && lineItem.type === 'product');
            });

            this.missingProductLineItems.forEach((lineItem) => {
                lineItem.type = 'custom';
                lineItem.productId = null;
                lineItem.referencedId = null;
                lineItem.payload.isConvertedProductLineItem = true;
            });

            if (this.missingProductLineItems.length === 0) {
                return Promise.resolve();
            }

            return this.orderRepository.save(this.order, this.versionContext);
        },
    },
});
