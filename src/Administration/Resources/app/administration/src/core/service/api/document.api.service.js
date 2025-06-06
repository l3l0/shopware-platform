import ApiService from '../api.service';

const { Feature } = Shopware;

const DocumentEvents = {
    DOCUMENT_FAILED: 'create-document-fail',
    DOCUMENT_FINISHED: 'create-document-finished',
};

/**
 * Gateway for the API end point "document"
 * @class
 * @extends ApiService
 */
class DocumentApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'document') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'documentService';
        this.$listener = () => ({});
    }

    createDocument(
        orderId,
        documentTypeName,
        config = {},
        referencedDocumentId = null,
        additionalParams = {},
        additionalHeaders = {},
        file = null,
    ) {
        if (Feature.isActive('v6.5.0.0')) {
            let route = `_action/order/document/${documentTypeName}/create`;
            const headers = this.getBasicHeaders(additionalHeaders);

            const params = {
                orderId,
                config,
                referencedDocumentId,
            };

            if (file || config.documentMediaFileId) {
                params.static = true;
            }

            this.httpClient
                .post(route, [params], {
                    additionalParams,
                    headers,
                })
                .then((response) => {
                    const responseDoc = response.data?.data;

                    if (file && file instanceof File && responseDoc && responseDoc[0]?.documentId) {
                        const documentId = responseDoc[0]?.documentId;
                        const fileName = file.name.split('.').shift();
                        const fileExtension = file.name.split('.').pop();
                        // eslint-disable-next-line max-len
                        route = `/_action/document/${documentId}/upload?fileName=${config.documentNumber}_${fileName}&extension=${fileExtension}`;
                        headers['Content-Type'] = file.type;
                        this.httpClient.post(route, file, {
                            additionalParams,
                            headers,
                        });
                    }

                    const errors = response.data?.errors;

                    if (errors && errors.hasOwnProperty(orderId)) {
                        this.$listener(
                            this.createDocumentEvent(DocumentEvents.DOCUMENT_FAILED, errors[orderId].pop()),
                        );

                        return;
                    }

                    this.$listener(this.createDocumentEvent(DocumentEvents.DOCUMENT_FINISHED));
                })
                .catch((error) => {
                    if (error.response?.data?.errors) {
                        this.$listener(
                            this.createDocumentEvent(DocumentEvents.DOCUMENT_FAILED, error.response.data.errors.pop()),
                        );
                    }
                });
        } else {
            let route = `/_action/order/${orderId}/document/${documentTypeName}`;
            const headers = this.getBasicHeaders(additionalHeaders);

            const params = {
                config,
                referenced_document_id: referencedDocumentId,
            };

            if (file || config.documentMediaFileId) {
                params.static = true;
            }

            this.httpClient
                .post(route, params, {
                    additionalParams,
                    headers,
                }).then((response) => {
                    if (file && file instanceof File && response.data.documentId) {
                        const fileName = file.name.split('.').shift();
                        const fileExtension = file.name.split('.').pop();
                        // eslint-disable-next-line max-len
                        route = `/_action/document/${response.data.documentId}/upload?fileName=${config.documentNumber}_${fileName}&extension=${fileExtension}`;
                        headers['Content-Type'] = file.type;
                        this.httpClient.post(route, file, {
                            additionalParams,
                            headers,
                        });
                    }

                    this.$listener(this.createDocumentEvent(DocumentEvents.DOCUMENT_FINISHED));
                }).catch((error) => {
                    if (error.response?.data?.errors) {
                        this.$listener(
                            this.createDocumentEvent(DocumentEvents.DOCUMENT_FAILED, error.response.data.errors.pop()),
                        );
                    }
                });
        }
    }

    getDocumentPreview(orderId, orderDeepLink, documentTypeName, params) {
        const config = JSON.stringify(params);

        return this.httpClient
            .get(
                `/_action/order/${orderId}/${orderDeepLink}/document/${documentTypeName}/preview`,
                {
                    params: { config },
                    responseType: 'blob',
                    headers: this.getBasicHeaders(),
                },
            );
    }

    getDocument(documentId, documentDeepLink, context, download = false) {
        return this.httpClient
            .get(
                `/_action/document/${documentId}/${documentDeepLink}${download ? '?download=1' : ''}`,
                {
                    responseType: 'blob',
                    headers: this.getBasicHeaders(),
                },
            );
    }

    createDocumentEvent(action, payload) {
        return { action, payload };
    }

    setListener(callback) {
        this.$listener = callback;
    }
}

export { DocumentApiService as default, DocumentEvents };
