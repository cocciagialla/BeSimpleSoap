<?php

/*
 * This file is part of the BeSimpleSoapBundle.
 *
 * (c) Christian Kerl <christian-kerl@web.de>
 * (c) Francis Besset <francis.besset@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace BeSimple\SoapBundle\Controller;

use BeSimple\SoapBundle\Soap\SoapRequest;
use BeSimple\SoapBundle\Soap\SoapResponse;
use BeSimple\SoapBundle\WebServiceContext;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @author Christian Kerl <christian-kerl@web.de>
 * @author Francis Besset <francis.besset@gmail.com>
 */
class SoapWebServiceController implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var \SoapServer
     */
    protected $soapServer;

    /**
     * @var \BeSimple\SoapBundle\Soap\SoapRequest
     */
    protected $soapRequest;

    /**
     * @var \BeSimple\SoapBundle\Soap\SoapResponse
     */
    protected $soapResponse;

    /**
     * @var \BeSimple\SoapBundle\ServiceBinding\ServiceBinder
     */
    protected $serviceBinder;

    /**
     * @var array
     */
    private $headers = array();

    /**
     * @return \BeSimple\SoapBundle\Soap\SoapResponse
     */
    public function callAction($webservice)
    {
        /** @var WebServiceContext $webServiceContext */
        $webServiceContext   = $this->getWebServiceContext($webservice);

        $this->serviceBinder = $webServiceContext->getServiceBinder();

        $this->soapRequest = SoapRequest::createFromHttpRequest($this->container->get('request_stack')->getCurrentRequest());
        $this->soapServer  = $webServiceContext
            ->getServerBuilder()
            ->withSoapVersion11()
            ->withHandler($this)
            ->build()
        ;

        ob_start();
        $this->soapServer->handle($this->soapRequest->getSoapMessage());

        $response = $this->getResponse();
        $response->setContent(ob_get_clean());

        // The Symfony 2.0 Response::setContent() does not return the Response instance
        return $response;
    }

    /**
     * @return Response
     */
    public function definitionAction($webservice)
    {
        $response = new Response($this->getWebServiceContext($webservice)->getWsdlFileContent(
            $this->container->get('router')->generate(
                '_webservice_call',
                array('webservice' => $webservice),
                UrlGeneratorInterface::ABSOLUTE_URL
            )
        ));

        /** @var Request $request */
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $query = $request->query;
        if ($query->has('wsdl') || $query->has('WSDL')) {
            $request->setRequestFormat('wsdl');
        }

        return $response;
    }

    /**
     * This method gets called once for every SOAP header the \SoapServer received
     * and afterwards once for the called SOAP operation.
     *
     * @param string $method The SOAP header or SOAP operation name
     * @param array $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        if ($this->serviceBinder->isServiceMethod($method)) {
            // @TODO Add all SoapHeaders in SoapRequest
            foreach ($this->headers as $name => $value) {
                if ($this->serviceBinder->isServiceHeader($method, $name)) {
                    $this->soapRequest->getSoapHeaders()->add($this->serviceBinder->processServiceHeader($method, $name, $value));
                }
            }
            $this->headers = null;

            $this->soapRequest->attributes->add(
                $this->serviceBinder->processServiceMethodArguments($method, $arguments)
            );

            // forward to controller
            $response = $this->container->get('http_kernel')->handle($this->soapRequest, HttpKernelInterface::SUB_REQUEST, false);

            $this->setResponse($response);

            // add response soap headers to soap server
            foreach ($response->getSoapHeaders() as $header) {
                $this->soapServer->addSoapHeader($header->toNativeSoapHeader());
            }

            // return operation return value to soap server
            return $this->serviceBinder->processServiceMethodReturnValue(
                $method,
                $response->getReturnValue()
            );
        } else {
            // collect request soap headers
            $this->headers[$method] = $arguments[0];
        }
    }

    /**
     * @return \BeSimple\SoapBundle\Soap\SoapRequest
     */
    protected function getRequest()
    {
        return $this->soapRequest;
    }

    /**
     * @return \BeSimple\SoapBundle\Soap\SoapResponse
     */
    protected function getResponse()
    {
        return $this->soapResponse ?: $this->soapResponse = $this->container->get('besimple.soap.response');
    }

    /**
     * Set the SoapResponse
     *
     * @param Response $response A response to check and set
     *
     * @return \BeSimple\SoapBundle\Soap\SoapResponse A valid SoapResponse
     *
     * @throws InvalidArgumentException If the given Response is not an instance of SoapResponse
     */
    protected function setResponse(Response $response)
    {
        if (!$response instanceof SoapResponse) {
            throw new \InvalidArgumentException('You must return an instance of BeSimple\SoapBundle\Soap\SoapResponse');
        }

        return $this->soapResponse = $response;
    }

    protected function getWebServiceContext($webservice)
    {
        $context = sprintf('besimple.soap.context.%s', $webservice);

        if (!$this->container->has($context)) {
            throw new NotFoundHttpException(sprintf('No WebService with name "%s" found.', $webservice));
        }

        return $this->container->get($context);
    }
}
