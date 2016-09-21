<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\FrameworkExtraBundle\EventListener;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * Handles the Template annotation for actions.
 *
 * Depends on pre-processing of the ControllerListener.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class TemplateListener implements EventSubscriberInterface
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Guesses the template name to render and its variables and adds them to
     * the request object.
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        $request = $event->getRequest();
        $template = $request->attributes->get('_template');

        // no @Template present
        if (null === $template) {
            return;
        }

        // we need the @Template annotation object or we cannot continue
        if (!$template instanceof Template) {
            throw new \InvalidArgumentException('Request attribute "_template" is reserved for @Template annotations.');
        }

        $template->setOwner($controller = $event->getController());

        // when no template has been given, try to resolve it based on the controller
        if (null === $template->getTemplate()) {
            $guesser = $this->container->get('sensio_framework_extra.view.guesser');
            $template->setTemplate($guesser->guessTemplateName($controller, $request));
        }
    }

    /**
     * Renders the template and initializes a new response object with the
     * rendered template content.
     */
    public function onKernelView(GetResponseForControllerResultEvent $event)
    {
        /* @var Template $template */
        $request = $event->getRequest();
        $template = $request->attributes->get('_template');

        if (null === $template) {
            return;
        }

        if (!$this->container->has('twig')) {
            throw new \LogicException('You can not use the "@Template" annotation if the Twig Bundle is not available.');
        }

        $parameters = $event->getControllerResult();
        $owner = $template->getOwner();
        list($controller, $action) = $owner;

        // when the annotation declares no default vars and the action returns
        // null, all action method arguments are used as default vars
        if (null === $parameters) {
            $parameters = $this->resolveDefaultParameters($request, $template, $controller, $action);
        }

        // attempt to render the actual response
        $twig = $this->container->get('twig');

        if ($template->isStreamable()) {
            $callback = function () use ($twig, $template, $parameters) {
                $twig->display($template->getTemplate(), $parameters);
            };

            $event->setResponse(new StreamedResponse($callback));
        }

        // make sure the owner (controller+dependencies) is not cached or stored elsewhere
        $template->setOwner(array());

        $event->setResponse(new Response($twig->render($template->getTemplate(), $parameters)));
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::CONTROLLER => array('onKernelController', -128),
            KernelEvents::VIEW => 'onKernelView',
        );
    }

    private function resolveDefaultParameters(Request $request, Template $template, $controller, $action)
    {
        $parameters = array();
        $arguments = $template->getVars();

        if (0 === count($arguments)) {
            $r = new \ReflectionObject($controller);

            $arguments = array();
            foreach ($r->getMethod($action)->getParameters() as $param) {
                $arguments[] = $param->getName();
            }
        }

        // fetch the arguments of @Template.vars or everything if desired
        // and assign them to the designated template
        foreach ($arguments as $argument) {
            $parameters[$argument] = $request->attributes->get($argument);
        }

        return $parameters;
    }
}
