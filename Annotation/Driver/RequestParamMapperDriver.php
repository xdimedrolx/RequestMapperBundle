<?php

namespace Vangrg\RequestMapperBundle\Annotation\Driver;

use Doctrine\Common\Annotations\Reader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;
use Vangrg\RequestMapperBundle\Event\ConfigurationEvent;
use Vangrg\RequestMapperBundle\Mapper\RequestMapperInterface;
use Vangrg\RequestMapperBundle\Annotation\RequestParamMapper;
use Vangrg\RequestMapperBundle\Exception\ValidationException;

/**
 * Class RequestParamMapperDriver.
 *
 * @author Ivan Griga <grigaivan2@gmail.com>
 */
class RequestParamMapperDriver implements EventSubscriberInterface
{
    /** @var RequestMapperInterface */
    private $requestMapper;

    /** @var Reader */
    private $reader;

    /** @var ValidatorInterface */
    private $validator;

    /** @var RequestParamMapper|false */
    private $configuration;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => [
                ['prepareConfig', 9999],
                ['mapRequestData', -9999],
            ],
        ];
    }

    public function __construct(
        RequestMapperInterface $requestMapper,
        Reader $reader,
        ValidatorInterface $validator,
        EventDispatcherInterface $dispatcher
    ) {
        $this->requestMapper = $requestMapper;
        $this->reader = $reader;
        $this->validator = $validator;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param FilterControllerEvent|ControllerEvent $event
     *
     * @throws \ReflectionException
     */
    public function prepareConfig($event)
    {
        if (!$event instanceof FilterControllerEvent && !$event instanceof ControllerEvent) {
            throw new \InvalidArgumentException(\sprintf('Expected instance of type %s, %s given', \class_exists(ControllerEvent::class) ? ControllerEvent::class : FilterControllerEvent::class, \is_object($event) ? \get_class($event) : \gettype($event)));
        }

        if (!is_array($controller = $event->getController())) {
            return;
        }

        $object = new \ReflectionClass($controller[0]);
        $method = $object->getMethod($controller[1]);

        $this->configuration = $this->_getConfiguration($this->reader->getMethodAnnotations($method));

        if (false == $this->configuration) {
            return;
        }

        $configurationEvent = new ConfigurationEvent($this->configuration, $event->getRequest());

        if ($this->dispatcher instanceof ContractsEventDispatcherInterface) {
            $this->dispatcher->dispatch($configurationEvent, ConfigurationEvent::NAME);
        } else {
            $this->dispatcher->dispatch(ConfigurationEvent::NAME, $configurationEvent);
        }

        if (!isset($this->configuration->param, $this->configuration->class)) {
            throw new \LogicException(sprintf(
                "No configured annotation options 'param' or 'class' for '%s' action in '%s'",
                $method->getName(),
                $object->getName()
            ));
        }

        if (false === $this->configuration->toExistedObject) {
            $event->getRequest()->attributes->set($this->configuration->param, new $this->configuration->class());
        }
    }

    /**
     * @param FilterControllerEvent|ControllerEvent $event
     */
    public function mapRequestData($event)
    {
        if (false == $this->configuration) {
            return;
        }

        if (!$event instanceof FilterControllerEvent && !$event instanceof ControllerEvent) {
            throw new \InvalidArgumentException(\sprintf('Expected instance of type %s, %s given', \class_exists(ControllerEvent::class) ? ControllerEvent::class : FilterControllerEvent::class, \is_object($event) ? \get_class($event) : \gettype($event)));
        }

        $object = $this->requestMapper->map($event->getRequest(), $this->configuration);

        if ($this->configuration->validate) {
            $errors = $this->validator->validate($object, null, $this->configuration->validationGroups);

            if (count($errors) > 0) {
                throw new ValidationException('Validation Failed', $errors);
            }
        }

        $event->getRequest()->attributes->set($this->configuration->param, $object);
    }

    /**
     * @param array $annotations
     *
     * @return false|RequestParamMapper
     */
    private function _getConfiguration(array $annotations)
    {
        foreach ($annotations as $configuration) {
            if ($configuration instanceof RequestParamMapper) {
                return $configuration;
            }
        }

        return false;
    }
}