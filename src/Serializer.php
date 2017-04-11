<?php

/*
 * Copyright 2016 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\Serializer;

use JMS\Serializer\Construction\ObjectConstructorInterface;
use JMS\Serializer\ContextFactory\DefaultDeserializationContextFactory;
use JMS\Serializer\ContextFactory\DefaultSerializationContextFactory;
use JMS\Serializer\ContextFactory\DeserializationContextFactoryInterface;
use JMS\Serializer\ContextFactory\SerializationContextFactoryInterface;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\EventDispatcher\EventDispatcherInterface;
use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\Exception\UnsupportedFormatException;
use JMS\Serializer\Expression\ExpressionEvaluatorInterface;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use JMS\Serializer\Util\ArrayObject;
use Metadata\MetadataFactoryInterface;
use PhpCollection\MapInterface;

/**
 * Serializer Implementation.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Serializer implements SerializerInterface, ArrayTransformerInterface
{
    private $factory;
    private $handlerRegistry;
    private $objectConstructor;
    private $dispatcher;
    private $typeParser;

    /** @var \PhpCollection\MapInterface */
    private $serializationVisitors;

    /** @var \PhpCollection\MapInterface */
    private $deserializationVisitors;

    private $serializationNavigator;
    private $deserializationNavigator;

    /**
     * @var SerializationContextFactoryInterface
     */
    private $serializationContextFactory;

    /**
     * @var DeserializationContextFactoryInterface
     */
    private $deserializationContextFactory;

    /**
     * Constructor.
     *
     * @param \Metadata\MetadataFactoryInterface $factory
     * @param Handler\HandlerRegistryInterface $handlerRegistry
     * @param Construction\ObjectConstructorInterface $objectConstructor
     * @param \PhpCollection\MapInterface $serializationVisitors of VisitorInterface
     * @param \PhpCollection\MapInterface $deserializationVisitors of VisitorInterface
     * @param EventDispatcher\EventDispatcherInterface $dispatcher
     * @param TypeParser $typeParser
     * @param ExpressionEvaluatorInterface|null $expressionEvaluator
     */
    public function __construct(
        MetadataFactoryInterface $factory,
        HandlerRegistryInterface $handlerRegistry,
        ObjectConstructorInterface $objectConstructor,
        MapInterface $serializationVisitors,
        MapInterface $deserializationVisitors,
        EventDispatcherInterface $dispatcher = null,
        TypeParser $typeParser = null,
        ExpressionEvaluatorInterface $expressionEvaluator = null
    )
    {
        $this->factory = $factory;
        $this->handlerRegistry = $handlerRegistry;
        $this->objectConstructor = $objectConstructor;
        $this->dispatcher = $dispatcher ?: new EventDispatcher();
        $this->typeParser = $typeParser ?: new TypeParser();
        $this->serializationVisitors = $serializationVisitors;
        $this->deserializationVisitors = $deserializationVisitors;

        $this->serializationNavigator = new SerializationGraphNavigator($this->factory, $this->handlerRegistry, $this->dispatcher, $expressionEvaluator);
        $this->deserializationNavigator = new DeserializationGraphNavigator($this->factory, $this->handlerRegistry, $this->dispatcher, $objectConstructor);

        $this->serializationContextFactory = new DefaultSerializationContextFactory();
        $this->deserializationContextFactory = new DefaultDeserializationContextFactory();
    }

    public function serialize($data, $format, SerializationContext $context = null, $type = null)
    {
        if (null === $context) {
            $context = $this->serializationContextFactory->createSerializationContext();
        }

        return $this->serializationVisitors->get($format)
            ->map(function (SerializationVisitorInterface $visitor) use ($context, $data, $format, $type) {

                $type = $this->findInitialType($type, $context);

                $this->visit($this->serializationNavigator, $visitor, $context, $visitor->prepare($data), $format, $type);

                return $visitor->getResult();
            })
            ->getOrThrow(new UnsupportedFormatException(sprintf('The format "%s" is not supported for serialization.', $format)));
    }

    private function findInitialType($type, SerializationContext $context)
    {
        if ($type !== null) {
            return $this->typeParser->parse($type);
        } elseif ($context->attributes->containsKey('initial_type')) {
            return $this->typeParser->parse($context->attributes->get('initial_type')->get());
        }
        return null;
    }

    public function deserialize($data, $type, $format, DeserializationContext $context = null)
    {
        if (null === $context) {
            $context = $this->deserializationContextFactory->createDeserializationContext();
        }

        return $this->deserializationVisitors->get($format)
            ->map(function (DeserializationVisitorInterface $visitor) use ($context, $data, $format, $type) {
                $preparedData = $visitor->prepare($data);
                return $this->visit($this->deserializationNavigator, $visitor, $context, $preparedData, $format, $this->typeParser->parse($type));
            })
            ->getOrThrow(new UnsupportedFormatException(sprintf('The format "%s" is not supported for deserialization.', $format)));
    }

    /**
     * {@InheritDoc}
     */
    public function toArray($data, SerializationContext $context = null, $type = null)
    {
        if (null === $context) {
            $context = $this->serializationContextFactory->createSerializationContext();
        }

        return $this->serializationVisitors->get('json')
            ->map(function (JsonSerializationVisitor $visitor) use ($context, $data, $type) {

                $type = $this->findInitialType($type, $context);

                $result = $this->visit($this->serializationNavigator, $visitor, $context, $data, 'json', $type);
                $result = $this->removeInternalArrayObjects($result);

                if (!is_array($result)) {
                    throw new RuntimeException(sprintf(
                        'The input data of type "%s" did not convert to an array, but got a result of type "%s".',
                        is_object($data) ? get_class($data) : gettype($data),
                        is_object($result) ? get_class($result) : gettype($result)
                    ));
                }

                return $result;
            })
            ->get();
    }

    /**
     * {@InheritDoc}
     */
    public function fromArray(array $data, $type, DeserializationContext $context = null)
    {
        if (null === $context) {
            $context = $this->deserializationContextFactory->createDeserializationContext();
        }

        return $this->deserializationVisitors->get('json')
            ->map(function (JsonDeserializationVisitor $visitor) use ($data, $type, $context) {
                return $this->visit($this->deserializationNavigator, $visitor, $context, $data, 'json', $this->typeParser->parse($type));
            })
            ->get();
    }

    private function visit(GraphNavigatorInterface $navigator, $visitor, Context $context, $data, $format, array $type = null)
    {
        $context->initialize(
            $format,
            $visitor,
            $navigator,
            $this->factory
        );

        if (method_exists($visitor, 'initialize')){
            $visitor->initialize($navigator, $data);
            $result = $navigator->accept($data, $type, $context);
            $visitor->setResult($result);
            return $result;
        } else {
            $visitor->setNavigator($navigator);
            return $navigator->accept($data, $type, $context);
        }
    }

    private function removeInternalArrayObjects($data)
    {
        if ($data instanceof ArrayObject) {
            $data = $data->getArrayCopy();
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->removeInternalArrayObjects($v);
            }
        }

        return $data;
    }

    /**
     * @return MetadataFactoryInterface
     */
    public function getMetadataFactory()
    {
        return $this->factory;
    }

    /**
     * @param SerializationContextFactoryInterface $serializationContextFactory
     *
     * @return self
     */
    public function setSerializationContextFactory(SerializationContextFactoryInterface $serializationContextFactory)
    {
        $this->serializationContextFactory = $serializationContextFactory;

        return $this;
    }

    /**
     * @param DeserializationContextFactoryInterface $deserializationContextFactory
     *
     * @return self
     */
    public function setDeserializationContextFactory(DeserializationContextFactoryInterface $deserializationContextFactory)
    {
        $this->deserializationContextFactory = $deserializationContextFactory;

        return $this;
    }
}
