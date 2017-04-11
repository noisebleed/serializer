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

use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;
use JMS\Serializer\Util\ArrayObject;

class JsonSerializationVisitor extends AbstractVisitor implements SerializationVisitorInterface
{
    private $options = 0;

    private $navigator;
    private $root;
    private $dataStack;
    private $data;

    public function setNavigator(GraphNavigatorInterface $navigator)
    {

    }

    public function initialize(GraphNavigatorInterface $navigator, $data)
    {
        $this->navigator = $navigator;
        $this->dataStack = new \SplStack;
    }

    public function visitNull($data, array $type, Context $context)
    {
        return null;
    }

    public function visitString($data, array $type, Context $context)
    {
        return (string)$data;
    }

    public function visitBoolean($data, array $type, Context $context)
    {
        return (boolean)$data;
    }

    public function visitInteger($data, array $type, Context $context)
    {
        return (int)$data;
    }

    public function visitDouble($data, array $type, Context $context)
    {
        return (float)$data;
    }

    /**
     * @param array $data
     * @param array $type
     * @param Context $context
     * @return mixed
     */
    public function visitArray($data, array $type, Context $context)
    {
        $this->dataStack->push($data);

        $isHash = isset($type['params'][1]);

        $rs = $isHash ? new ArrayObject() : array();

        $isList = isset($type['params'][0]) && !isset($type['params'][1]);

        foreach ($data as $k => $v) {
            $v = $this->navigator->accept($v, $this->getElementType($type), $context);

            if (null === $v && $context->shouldSerializeNull() !== true) {
                continue;
            }

            if ($isList) {
                $rs[] = $v;
            } else {
                $rs[$k] = $v;
            }
        }

        $this->dataStack->pop();

        return $rs;
    }

    public function startVisitingObject(ClassMetadata $metadata, $data, array $type, Context $context)
    {
        $this->dataStack->push($this->data);
        $this->data = new ArrayObject();
    }

    public function endVisitingObject(ClassMetadata $metadata, $data, array $type, Context $context)
    {
        $rs = $this->data;
        $this->data = $this->dataStack->pop();

        return $rs;
    }

    public function visitProperty(PropertyMetadata $metadata, $data, Context $context)
    {
        $v = $this->accessor->getValue($data, $metadata);

        $v = $this->navigator->accept($v, $metadata->type, $context);
        if (null === $v && $context->shouldSerializeNull() !== true) {
            return;
        }

        $k = $this->namingStrategy->translateName($metadata);

        if ($metadata->inline && ($v instanceof ArrayObject)) {
            $this->data->merge($v);

            return;
        }

        $this->data[$k] = $v;
    }

    /**
     * Checks if some data key exists.
     *
     * @param string $key
     * @return boolean
     */
    public function hasData($key)
    {
        return isset($this->data[$key]);
    }

    /**
     * Allows you to replace existing data on the current object/root element.
     *
     * @param string $key
     * @param integer|float|boolean|string|array|null $value This value must either be a regular scalar, or an array.
     *                                                       It must not contain any objects anymore.
     */
    public function setData($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function getRoot()
    {
        return $this->root;
    }

    /**
     * @param mixed $data the passed data must be understood by whatever encoding function is applied later.
     */
    public function setResult($data)
    {
        $this->root = $data;
    }

    public function getResult()
    {
        $result = @json_encode($this->root, $this->options);

        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                return $result;

            case JSON_ERROR_UTF8:
                throw new RuntimeException('Your data could not be encoded because it contains invalid UTF8 characters.');

            default:
                throw new RuntimeException(sprintf('An error occurred while encoding your data (error code %d).', json_last_error()));
        }
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setOptions($options)
    {
        $this->options = (integer)$options;
    }
}
