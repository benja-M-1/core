<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\OpenApi\Factory;

use ApiPlatform\Core\Api\FilterLocatorTrait;
use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\DataProvider\PaginationOptions;
use ApiPlatform\Core\JsonSchema\Schema;
use ApiPlatform\Core\JsonSchema\SchemaFactoryInterface;
use ApiPlatform\Core\JsonSchema\TypeFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\ResourceMetadata;
use ApiPlatform\Core\OpenApi\Model;
use ApiPlatform\Core\OpenApi\Model\ExternalDocumentation;
use ApiPlatform\Core\OpenApi\OpenApi;
use ApiPlatform\Core\OpenApi\Options;
use ApiPlatform\Core\Operation\Factory\SubresourceOperationFactoryInterface;
use ApiPlatform\Core\PathResolver\OperationPathResolverInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\PropertyInfo\Type;

/**
 * Generates an Open API v3 specification.
 */
final class OpenApiFactory implements OpenApiFactoryInterface
{
    use FilterLocatorTrait;

    public const BASE_URL = 'base_url';

    private $resourceNameCollectionFactory;
    private $resourceMetadataFactory;
    private $propertyNameCollectionFactory;
    private $propertyMetadataFactory;
    private $operationPathResolver;
    private $subresourceOperationFactory;
    private $formats;
    private $jsonSchemaFactory;
    private $jsonSchemaTypeFactory;
    private $openApiOptions;
    private $paginationOptions;

    public function __construct(ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory, ResourceMetadataFactoryInterface $resourceMetadataFactory, PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, SchemaFactoryInterface $jsonSchemaFactory, TypeFactoryInterface $jsonSchemaTypeFactory, OperationPathResolverInterface $operationPathResolver, ContainerInterface $filterLocator, SubresourceOperationFactoryInterface $subresourceOperationFactory, array $formats = [], Options $openApiOptions, PaginationOptions $paginationOptions)
    {
        $this->resourceNameCollectionFactory = $resourceNameCollectionFactory;
        $this->jsonSchemaFactory = $jsonSchemaFactory;
        $this->jsonSchemaTypeFactory = $jsonSchemaTypeFactory;
        $this->formats = $formats;
        $this->setFilterLocator($filterLocator, true);
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->propertyNameCollectionFactory = $propertyNameCollectionFactory;
        $this->propertyMetadataFactory = $propertyMetadataFactory;
        $this->operationPathResolver = $operationPathResolver;
        $this->openApiOptions = $openApiOptions;
        $this->paginationOptions = $paginationOptions;
        $this->subresourceOperationFactory = $subresourceOperationFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $context = []): OpenApi
    {
        $baseUrl = $context[self::BASE_URL] ?? '/';
        $info = new Model\Info($this->openApiOptions->getTitle(), $this->openApiOptions->getVersion(), trim($this->openApiOptions->getDescription()));
        $servers = '/' === $baseUrl || '' === $baseUrl ? [new Model\Server('/')] : [new Model\Server($baseUrl)];
        $paths = new Model\Paths();
        $links = [];
        $schemas = [];

        foreach ($this->resourceNameCollectionFactory->create() as $resourceClass) {
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
            $resourceShortName = $resourceMetadata->getShortName();

            // Items needs to be parsed first to be able to reference the lines from the collection operation
            list($itemOperationLinks, $itemOperationSchemas) = $this->collectPaths($resourceMetadata, $resourceClass, OperationType::ITEM, $context, $paths, $links, $schemas);
            $schemas += $itemOperationSchemas;
            list($collectionOperationLinks, $collectionOperationSchemas) = $this->collectPaths($resourceMetadata, $resourceClass, OperationType::COLLECTION, $context, $paths, $links, $schemas);

            list($subresourceOperationLinks, $subresourceOperationSchemas) = $this->collectPaths($resourceMetadata, $resourceClass, OperationType::SUBRESOURCE, $context, $paths, $links, $schemas);
            $schemas += $collectionOperationSchemas;
        }

        $securitySchemes = $this->getSecuritySchemes();
        $securityRequirements = [];

        foreach (array_keys($securitySchemes) as $key) {
            $securityRequirements[$key] = [];
        }

        return new OpenApi($info, $servers, $paths, new Model\Components(new \ArrayObject($schemas), new \ArrayObject(), new \ArrayObject(), new \ArrayObject(), new \ArrayObject(), new \ArrayObject(), new \ArrayObject($securitySchemes)), $securityRequirements);
    }

    /**
     * @return array | array
     */
    private function collectPaths(ResourceMetadata $resourceMetadata, string $resourceClass, string $operationType, array $context, Model\Paths $paths, array &$links, array $schemas = []): array
    {
        $resourceShortName = $resourceMetadata->getShortName();
        $operations = OperationType::COLLECTION === $operationType ? $resourceMetadata->getCollectionOperations() : (OperationType::ITEM === $operationType ? $resourceMetadata->getItemOperations() : $this->subresourceOperationFactory->create($resourceClass));
        if (!$operations) {
            return [$links, $schemas];
        }

        foreach ($operations as $operationName => $operation) {
            $path = $this->getPath($resourceShortName, $operationName, $operation, $operationType);
            $method = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'method', 'GET');
            list($requestMimeTypes, $responseMimeTypes) = $this->getMimeTypes($resourceClass, $operationName, $operationType, $resourceMetadata);
            $operationId = $operation['openapi_context']['operationId'] ?? lcfirst($operationName).ucfirst($resourceShortName).ucfirst($operationType);
            $linkedOperationId = 'get'.ucfirst($resourceShortName).ucfirst(OperationType::ITEM);
            $pathItem = $paths->getPath($path) ?: new Model\PathItem();

            $operationOutputSchemas = [];
            foreach ($responseMimeTypes as $operationFormat) {
                $operationOutputSchema = $this->jsonSchemaFactory->buildSchema($resourceClass, $operationFormat, Schema::TYPE_OUTPUT, $operationType, $operationName, new Schema('openapi'), $context);
                $schemas += $operationOutputSchema->getDefinitions()->getArrayCopy();
                $operationOutputSchemas[$operationFormat] = $operationOutputSchema;
            }

            $parameters = [];
            $responses = [];

            // Set up parameters
            if (OperationType::ITEM === $operationType) {
                $parameters[] = new Model\Parameter('id', 'path', 'Resource identifier', true, false, false, ['type' => 'string']);
                $links[$operationId] = $this->getLink($resourceClass, $operationId, $path);
            } elseif (OperationType::COLLECTION === $operationType && 'GET' === $method) {
                $parameters = array_merge($parameters, $this->getPaginationParameters($resourceMetadata, $operationName), $this->getFiltersParameters($resourceMetadata, $operationName, $resourceClass));
            } elseif (OperationType::SUBRESOURCE === $operationType) {
                // FIXME: In SubresourceOperationFactory identifiers may happen twice
                $added = [];
                foreach ($operation['identifiers'] as $identifier) {
                    if (\in_array($identifier[0], $added, true)) {
                        continue;
                    }
                    $added[] = $identifier[0];
                    $parameterShortname = $this->resourceMetadataFactory->create($identifier[1])->getShortName();
                    $parameters[] = new Model\Parameter($identifier[0], 'path', $parameterShortname.' identifier', true, false, false, ['type' => 'string']);
                }

                if ($operation['collection']) {
                    $parameters = array_merge($parameters, $this->getPaginationParameters($resourceMetadata, $operationName), $this->getFiltersParameters($resourceMetadata, $operationName, $resourceClass));
                }
            }

            // Create responses
            switch ($method) {
                case 'GET':
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '200');
                    $responseContent = $this->buildContent($responseMimeTypes, $operationOutputSchemas);
                    $responses[$successStatus] = new Model\Response(sprintf('%s %s', $resourceShortName, OperationType::COLLECTION === $operationType ? 'collection' : 'resource'), $responseContent);
                    break;
                case 'POST':
                    $responseLinks = new \ArrayObject(isset($links[$linkedOperationId]) ? [ucfirst($linkedOperationId) => $links[$linkedOperationId]] : []);
                    $responseContent = $this->buildContent($responseMimeTypes, $operationOutputSchemas);
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '201');
                    $responses[$successStatus] = new Model\Response(sprintf('%s resource created', $resourceShortName), $responseContent, null, $responseLinks);
                    $responses['400'] = new Model\Response('Invalid input');
                    break;
                case 'PATCH':
                case 'PUT':
                    $responseLinks = new \ArrayObject(isset($links[$linkedOperationId]) ? [ucfirst($linkedOperationId) => $links[$linkedOperationId]] : []);
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '200');
                    $responseContent = $this->buildContent($responseMimeTypes, $operationOutputSchemas);
                    $responses[$successStatus] = new Model\Response(sprintf('%s resource updated', $resourceShortName), $responseContent, null, $responseLinks);
                    $responses['400'] = new Model\Response('Invalid input');
                    break;
                case 'DELETE':
                    $successStatus = (string) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'status', '204');
                    $responses[$successStatus] = new Model\Response(sprintf('%s resource deleted', $resourceShortName));
                    break;
            }

            if (OperationType::ITEM === $operationType) {
                $responses['404'] = new Model\Response('Resource not found');
            }

            if (!$responses) {
                $responses['default'] = new Model\Response('Unexpected error');
            }

            $requestBody = null;
            if ('PUT' === $method || 'POST' === $method || 'PATCH' === $method) {
                $operationInputSchemas = [];
                foreach ($requestMimeTypes as $operationFormat) {
                    $operationInputSchema = $this->jsonSchemaFactory->buildSchema($resourceClass, $operationFormat, Schema::TYPE_INPUT, $operationType, $operationName, new Schema('openapi'), $context);
                    $schemas += $operationInputSchema->getDefinitions()->getArrayCopy();
                    $operationInputSchemas[$operationFormat] = $operationInputSchema;
                }

                $requestBody = new Model\RequestBody(sprintf('The %s %s resource', 'POST' === $method ? 'new' : 'updated', $resourceShortName), $this->buildContent($requestMimeTypes, $operationInputSchemas), true);
            }

            $pathItem = $pathItem->{'with'.ucfirst($method)}(new Model\Operation(
                $operationId,
                $operation['openapi_context']['tags'] ?? (OperationType::SUBRESOURCE === $operationType ? $operation['shortNames'] : [$resourceShortName]),
                $responses,
                $operation['openapi_context']['summary'] ?? '',
                $operation['openapi_context']['description'] ?? $this->getPathDescription($resourceShortName, $method, $operationType),
                isset($operation['openapi_context']['externalDocs']) ? new ExternalDocumentation($operation['openapi_context']['externalDocs']['description'] ?? null, $operation['openapi_context']['externalDocs']['url']) : null,
                $parameters,
                $requestBody,
                isset($operation['openapi_context']['callbacks']) ? new \ArrayObject($operation['openapi_context']['callbacks']) : null,
                $operation['openapi_context']['deprecated'] ?? (bool) $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'deprecation_reason', false, true),
                $operation['openapi_context']['security'] ?? null,
                $operation['openapi_context']['servers'] ?? null
            ));

            $paths->addPath($path, $pathItem);
        }

        return [$links, $schemas];
    }

    private function buildContent(array $responseMimeTypes, array $operationSchemas): \ArrayObject
    {
        $content = new \ArrayObject();

        foreach ($responseMimeTypes as $mimeType => $format) {
            $content[$mimeType] = new Model\MediaType(new \ArrayObject($operationSchemas[$format]->getArrayCopy(false)));
        }

        return $content;
    }

    private function getMimeTypes(string $resourceClass, string $operationName, string $operationType, ResourceMetadata $resourceMetadata = null): array
    {
        $requestFormats = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'input_formats', $this->formats, true);
        $responseFormats = $resourceMetadata->getTypedOperationAttribute($operationType, $operationName, 'output_formats', $this->formats, true);

        $requestMimeTypes = $this->flattenMimeTypes($requestFormats);
        $responseMimeTypes = $this->flattenMimeTypes($responseFormats);

        return [$requestMimeTypes, $responseMimeTypes];
    }

    private function flattenMimeTypes(array $responseFormats): array
    {
        $responseMimeTypes = [];
        foreach ($responseFormats as $responseFormat => $mimeTypes) {
            foreach ($mimeTypes as $mimeType) {
                $responseMimeTypes[$mimeType] = $responseFormat;
            }
        }

        return $responseMimeTypes;
    }

    /**
     * Gets the path for an operation.
     *
     * If the path ends with the optional _format parameter, it is removed
     * as optional path parameters are not yet supported.
     *
     * @see https://github.com/OAI/OpenAPI-Specification/issues/93
     */
    private function getPath(string $resourceShortName, string $operationName, array $operation, string $operationType): string
    {
        if ($operation['path'] ?? null) {
            return 0 === strpos($operation['path'], '/') ? $operation['path'] : '/'.$operation['path'];
        }
        $path = $this->operationPathResolver->resolveOperationPath($resourceShortName, $operation, $operationType, $operationName);
        if ('.{_format}' === substr($path, -10)) {
            $path = substr($path, 0, -10);
        }

        return $path;
    }

    private function getPathDescription(string $resourceShortName, string $method, string $operationType): string
    {
        switch ($method) {
            case 'GET':
                $pathSummary = OperationType::COLLECTION === $operationType ? 'Retrieves the collection of %s resources.' : 'Retrieves a %s resource.';
                break;
            case 'POST':
                $pathSummary = 'Creates a %s resource.';
                break;
            case 'PATCH':
                $pathSummary = 'Updates the %s resource.';
                break;
            case 'PUT':
                $pathSummary = 'Replaces the %s resource.';
                break;
            case 'DELETE':
                $pathSummary = 'Removes the %s resource.';
                break;
            default:
                return $resourceShortName;
        }

        return sprintf($pathSummary, $resourceShortName);
    }

    /**
     * @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md#linkObject.
     */
    private function getLink(string $resourceClass, string $operationId, string $path): Model\Link
    {
        $parameters = [];

        foreach ($this->propertyNameCollectionFactory->create($resourceClass) as $propertyName) {
            $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $propertyName);
            if (!$propertyMetadata->isIdentifier()) {
                continue;
            }

            $parameters[$propertyName] = sprintf('$response.body#/%s', $propertyName);
        }

        return new Model\Link(
            $operationId,
            new \ArrayObject($parameters),
            [],
            1 === \count($parameters) ? sprintf('The `%1$s` value returned in the response can be used as the `%1$s` parameter in `GET %2$s`.', key($parameters), $path) : sprintf('The values returned in the response can be used in `GET %s`.', $path)
        );
    }

    /**
     * Gets parameters corresponding to enabled filters.
     */
    private function getFiltersParameters(ResourceMetadata $resourceMetadata, string $operationName, string $resourceClass): array
    {
        $parameters = [];
        $resourceFilters = $resourceMetadata->getCollectionOperationAttribute($operationName, 'filters', [], true);
        foreach ($resourceFilters as $filterId) {
            if (!$filter = $this->getFilter($filterId)) {
                continue;
            }

            foreach ($filter->getDescription($resourceClass) as $name => $data) {
                $schema = $data['schema'] ?? \in_array($data['type'], Type::$builtinTypes, true) ? $this->jsonSchemaTypeFactory->getType(new Type($data['type'], false, null, $data['is_collection'] ?? false)) : ['type' => 'string'];

                $parameters[] = new Model\Parameter(
                    $name,
                    'query',
                    $data['description'] ?? '',
                    $data['required'] ?? false,
                    $data['openapi']['deprecated'] ?? false,
                    $data['openapi']['allowEmptyValue'] ?? true,
                    $schema,
                    'array' === $schema['type'] && \in_array($data['type'],
                    [Type::BUILTIN_TYPE_ARRAY, Type::BUILTIN_TYPE_OBJECT], true) ? 'deepObject' : 'form',
                    'array' === $schema['type'],
                    $data['openapi']['allowReserved'] ?? false,
                    $data['openapi']['example'] ?? null,
                    isset($data['openapi']['examples']
                ) ? new \ArrayObject($data['openapi']['examples']) : null);
            }
        }

        return $parameters;
    }

    private function getPaginationParameters(ResourceMetadata $resourceMetadata, string $operationName): array
    {
        if (!$this->paginationOptions->isPaginationEnabled()) {
            return [];
        }

        $parameters = [];

        if ($resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_enabled', true, true)) {
            $parameters[] = new Model\Parameter($this->paginationOptions->getPaginationPageParameterName(), 'query', 'The collection page number', false, false, true, ['type' => 'integer', 'default' => 1]);

            if ($resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_client_items_per_page', $this->paginationOptions->getClientItemsPerPage(), true)) {
                $schema = [
                    'type' => 'integer',
                    'default' => $resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_items_per_page', 30, true),
                    'minimum' => 0,
                ];

                if (null !== $maxItemsPerPage = $resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_maximum_items_per_page', null, true)) {
                    $schema['maximum'] = $maxItemsPerPage;
                }

                $parameters[] = new Model\Parameter($this->paginationOptions->getItemsPerPageParameterName(), 'query', 'The number of items per page', false, false, true, $schema);
            }
        }

        if ($resourceMetadata->getCollectionOperationAttribute($operationName, 'pagination_client_enabled', $this->paginationOptions->getPaginationClientEnabled(), true)) {
            $parameters[] = new Model\Parameter($this->paginationOptions->getPaginationClientEnabledParameterName(), 'query', 'Enable or disable pagination', false, false, true, ['type' => 'boolean']);
        }

        return $parameters;
    }

    private function getOauthSecurityScheme(): Model\SecurityScheme
    {
        $oauthFlow = new Model\OAuthFlow($this->openApiOptions->getOAuthAuthorizationUrl(), $this->openApiOptions->getOAuthTokenUrl(), $this->openApiOptions->getOAuthRefreshUrl(), new \ArrayObject($this->openApiOptions->getOAuthScopes()));
        $description = sprintf(
            'OAuth 2.0 %s Grant',
            strtolower(preg_replace('/[A-Z]/', ' \\0', lcfirst($this->openApiOptions->getOAuthFlow())))
        );
        $implicit = $password = $clientCredentials = $authorizationCode = null;

        switch ($this->openApiOptions->getOAuthFlow()) {
            case 'implicit':
                $implicit = $oauthFlow;
                break;
            case 'password':
                $password = $oauthFlow;
                break;
            case 'application':
            case 'clientCredentials':
                $clientCredentials = $oauthFlow;
                break;
            case 'accessCode':
            case 'authorizationCode':
                $authorizationCode = $oauthFlow;
                break;
            default:
                throw new \LogicException('OAuth flow must be one of: implicit, password, clientCredentials, authorizationCode');
        }

        return new Model\SecurityScheme($this->openApiOptions->getOAuthType(), $description, null, null, 'oauth2', null, new Model\OAuthFlows($implicit, $password, $clientCredentials, $authorizationCode), null);
    }

    private function getSecuritySchemes(): array
    {
        $securitySchemes = [];

        if ($this->openApiOptions->getOAuthEnabled()) {
            $securitySchemes['oauth'] = $this->getOauthSecurityScheme();
        }

        foreach ($this->openApiOptions->getApiKeys() as $key => $apiKey) {
            $description = sprintf('Value for the %s %s parameter.', $apiKey['name'], $apiKey['type']);
            $securitySchemes[$key] = new Model\SecurityScheme('apiKey', $description, $apiKey['name'], $apiKey['type'], 'bearer');
        }

        return $securitySchemes;
    }
}
