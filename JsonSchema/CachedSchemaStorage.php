<?php

namespace Sulu\Bundle\ValidationBundle\JsonSchema;

use JsonSchema\Entity\JsonPointer;
use JsonSchema\Iterator\ObjectIterator;
use JsonSchema\SchemaStorage;
use Sulu\Bundle\ValidationBundle\Exceptions\MalFormedJsonException;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Resource\FileResource;

class CachedSchemaStorage extends SchemaStorage
{
    const FILE_PREFIX = 'file://';

    /**
     * @var FileLocatorInterface
     */
    private $fileLocator;

    /**
     * @var array
     */
    private $configuredSchemas;

    /**
     * @var bool
     */
    private $debugMode;

    /**
     * @var string
     */
    private $cacheFilePath;

    /**
     * @param array $configuredSchemas array containing all file paths to configured schemas
     * @param FileLocatorInterface $fileLocator
     * @param string $cacheFilePath
     * @param string $environment
     */
    public function __construct(
        array $configuredSchemas,
        FileLocatorInterface $fileLocator,
        $cacheFilePath,
        $environment
    ) {
        parent::__construct();

        $this->fileLocator = $fileLocator;
        $this->configuredSchemas = $configuredSchemas;
        $this->debugMode = $environment !== 'prod';
        $this->cacheFilePath = $cacheFilePath;
        $this->initializeCache();
    }

    protected function initializeCache()
    {
        $schemaCache = new ConfigCache($this->cacheFilePath, $this->debugMode);

        if (!$schemaCache->isFresh()) {
            $resources = [];
            $processedSchemas = [];

            foreach ($this->configuredSchemas as $schemaPath) {
                $this->processSchema($schemaPath, $processedSchemas, $resources);
            }

            $schemaCache->write(serialize($processedSchemas), $resources);
        }

        $this->schemas = unserialize(file_get_contents($schemaCache->getPath()));;
    }

    /**
     * @param string $routeId
     * @return object
     */
    public function getSchemaByRoute($routeId)
    {
        $schemaFilePath = self::FILE_PREFIX . $this->fileLocator->locate($this->configuredSchemas[$routeId]);

        return $this->getSchema($schemaFilePath);
    }

    /**
     * @param string $schemaPath
     * @param array $serializedSchemas
     * @param array $resources
     *
     * @throws MalFormedJsonException
     */
    protected function processSchema($schemaPath, array &$serializedSchemas, array &$resources)
    {
        if (array_key_exists($schemaPath, $serializedSchemas)) {
            return;
        }

        $absoluteSchemaPath = $this->fileLocator->locate($schemaPath);
        $schema = json_decode(file_get_contents($absoluteSchemaPath));

        if ((json_last_error() !== JSON_ERROR_NONE)) {
            throw new MalFormedJsonException('Malformed json encountered in ' . $schemaPath);
        }

        if (substr($absoluteSchemaPath, 0, strlen(self::FILE_PREFIX)) !== self::FILE_PREFIX) {
            $absoluteSchemaPath = self::FILE_PREFIX . $absoluteSchemaPath;
        }

        $serializedSchemas[$absoluteSchemaPath] = $schema;
        $resources[] = new FileResource($absoluteSchemaPath);
        $this->processReferencesInSchema($schema, $absoluteSchemaPath, $serializedSchemas, $resources);
    }

    /**
     * @param object $schema
     * @param string $schemaFilePath
     * @param array $serializedSchemas
     * @param array $resources
     */
    protected function processReferencesInSchema($schema, $schemaFilePath, array &$serializedSchemas, array &$resources)
    {
        $objectIterator = new ObjectIterator($schema);
        foreach ($objectIterator as $toResolveSchema) {
            if (property_exists($toResolveSchema, '$ref') && is_string($toResolveSchema->{'$ref'})) {
                $jsonPointer = new JsonPointer(
                    $this->uriResolver->resolve($toResolveSchema->{'$ref'}, $schemaFilePath)
                );
                $toResolveSchema->{'$ref'} = (string)$jsonPointer;

                $this->processSchema($jsonPointer->getFilename(), $serializedSchemas, $resources);
            }
        }
    }
}
