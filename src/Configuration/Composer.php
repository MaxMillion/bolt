<?php
namespace Bolt\Configuration;

use Symfony\Component\HttpFoundation\Request;

class Composer extends Standard
{

    /**
     * Constructor initialises on the app root path.
     *
     * @param string $path
     */
    public function __construct($loader, Request $request = null)
    {
        parent::__construct($loader, $request);
        $this->setPath('composer', realpath(dirname(__DIR__) . '/../'));
        $this->setPath('app', realpath(dirname(__DIR__) . '/../app/'));
        $this->setUrl('app', '/bolt-public/');
    }
    
    public function getVerifier()
    {
        if (! $this->verifier) {
            $this->verifier = new ComposerChecks($this);
        }

        return $this->verifier;
    }
}
