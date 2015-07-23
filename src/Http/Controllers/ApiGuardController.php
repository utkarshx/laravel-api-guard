<?php

namespace Utkarshx\ApiGuard\Http\Controllers;

use App;

use Symfony\Component\HttpFoundation\Request;
use Utkarshx\ApiGuard\Repositories\ApiKeyRepository;
use Utkarshx\ApiGuard\Repositories\ApiLogRepository;
use Config;
use EllipseSynergie\ApiResponse\Laravel\Response;
use Exception;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Input;
use League\Fractal\Manager;
use Log;
use Route;

class ApiGuardController extends Controller
{

    /**
     * @var array
     */
    protected $apiMethods;

    /**
     * @var ApiKeyRepository
     */
    public $apiKey = null;

    public $objectMap = null;

    public $objectName = null;

    public $objectModel = null;

    /**
     * @var ApiLogRepository
     */
    public $apiLog = null;

    /**
     * @var Response
     */
    public $response;

    /**
     * @var Manager
     */
    public $manager;

    public function __construct()
    {
        $this->beforeFilter(function () {

            // Let's instantiate the response class first
            $this->manager = new Manager;

            $this->manager->parseIncludes(Input::get(Config::get('apiguard.includeKeyword', 'include'), 'include'));

            $this->response = new Response($this->manager);

            // apiguard might not be the only before filter on the controller
            // loop through any before filters and pull out $apiMethods in the controller
            $beforeFilters = $this->getBeforeFilters();

            foreach ($beforeFilters as $filter) {
                if ( ! empty($filter['options']['apiMethods'])) {
                    $apiMethods = $filter['options']['apiMethods'];
                }
            }

            // This is the actual request object used
            $request = Route::getCurrentRequest();

            // Let's get the method
            Str::parseCallback(Route::currentRouteAction(), null);

            $routeArray = Str::parseCallback(Route::currentRouteAction(), null);

            if (last($routeArray) == null) {
                // There is no method?
                return $this->response->errorMethodNotAllowed();
            }

            $method = last($routeArray);



            // We should check if key authentication is enabled for this method
            $keyAuthentication = true;

            if (isset($apiMethods[$method]['keyAuthentication']) && $apiMethods[$method]['keyAuthentication'] === false) {
                $keyAuthentication = false;
            }

            if(isset($apiMethods[$method]['objectName'])) {
                $this->objectName = $apiMethods[$method]['objectName'];
            }

            if ($keyAuthentication === true) {

                $key = $request->header(Config::get('apiguard.keyName', 'X-Authorization'));

                if (empty($key)) {
                    // Try getting the key from elsewhere
                    $key = Input::get(Config::get('apiguard.keyName', 'X-Authorization'));
                }

                if (empty($key)) {
                    // It's still empty!
                    return $this->response->errorUnauthorized();
                }

                $apiKeyModel = App::make(Config::get('apiguard.model', 'Utkarshx\ApiGuard\Models\ApiKey'));



                if ( ! $apiKeyModel instanceof ApiKeyRepository) {
                    Log::error('[Utkarshx/ApiGuard] You ApiKey model should be an instance of ApiKeyRepository.');
                    $exception = new Exception("You ApiKey model should be an instance of ApiKeyRepository.");
                    throw($exception);
                }

                $this->apiKey = $apiKeyModel->getByKey($key);
                if (empty($this->apiKey)) {
                    return $this->response->errorUnauthorized();
                }
                if(! empty($this->objectName)) {
                    if($this->objectName == 'class') {
                        $this->objectModel = App::make(Config::get('apiguard.classModel', 'Utkarshx\ApiGuard\Models\ClassModel'));
                    } elseif($this->objectName == 'group') {
                        $this->objectModel = App::make(Config::get('apiguard.groupModel', 'Utkarshx\ApiGuard\Models\GroupModel'));
                    }

                    if ( ! $this->objectModel instanceof ApiKeyRepository) {
                        Log::error('[Utkarshx/ApiGuard] You model should be an instance of ApiKeyRepository.');
                        $exception = new Exception("You model should be an instance of ApiKeyRepository.");
                        throw($exception);
                    }
                    $urlSegments = $request->segments();
                    $location = array_search($this->objectName, $urlSegments);
                    $objectKey = $urlSegments[$location+1];

                    $this->objectMap = $this->objectModel->getByUserIdAndObjectId($this->apiKey->user_id, $objectKey);

                    if (empty($this->objectMap)) {
                        return $this->response->errorWrongArgs();
                    }
                    // API key exists
                    // Check level of API
                    if ( ! empty($apiMethods[$method]['level'])) {
                        if ($this->objectMap->level < $apiMethods[$method]['level']) {

                            return $this->response->errorForbidden();
                        }
                    }
                }

            }

            $apiLog = App::make(Config::get('apiguard.apiLogModel', 'Utkarshx\ApiGuard\Models\ApiLog'));

            // Then check the limits of this method
            if ( ! empty($apiMethods[$method]['limits'])) {

                if (Config::get('apiguard.logging', true) === false) {
                    Log::warning("[Utkarshx/ApiGuard] You specified a limit in the $method method but API logging needs to be enabled in the configuration for this to work.");
                }

                $limits = $apiMethods[$method]['limits'];

                // We get key level limits first
                if ($this->apiKey != null && ! empty($limits['key'])) {

                    $keyLimit = ( ! empty($limits['key']['limit'])) ? $limits['key']['limit'] : 0;

                    if ($keyLimit == 0 || is_integer($keyLimit) == false) {
                        Log::warning("[Utkarshx/ApiGuard] You defined a key limit to the " . Route::currentRouteAction() . " route but you did not set a valid number for the limit variable.");
                    } else {
                        if ( ! $this->apiKey->ignore_limits) {
                            // This means the apikey is not ignoring the limits
                            $keyIncrement = ( ! empty($limits['key']['increment'])) ? $limits['key']['increment'] : Config::get('apiguard.keyLimitIncrement', '1 hour');
                            $keyIncrementTime = strtotime('-' . $keyIncrement);

                            if ($keyIncrementTime == false) {
                                Log::warning("[Utkarshx/ApiGuard] You have specified an invalid key increment time. This value can be any value accepted by PHP's strtotime() method");
                            } else {
                                // Count the number of requests for this method using this api key
                                $apiLogCount = $apiLog->countApiKeyRequests($this->apiKey->id, Route::currentRouteAction(), $request->getMethod(), $keyIncrementTime);

                                if ($apiLogCount >= $keyLimit) {
                                    Log::warning("[Utkarshx/ApiGuard] The API key ID#{$this->apiKey->id} has reached the limit of {$keyLimit} in the following route: " . Route::currentRouteAction());
                                    return $this->response->errorUnwillingToProcess('You have reached the limit for using this API.');
                                }
                            }
                        }
                    }
                }

                // Then the overall method limits
                if ( ! empty($limits['method'])) {

                    $methodLimit = ( ! empty($limits['method']['limit'])) ? $limits['method']['limit'] : 0;

                    if ($methodLimit == 0 || is_integer($methodLimit) == false) {
                        Log::warning("[Utkarshx/ApiGuard] You defined a method limit to the " . Route::currentRouteAction() . " route but you did not set a valid number for the limit variable.");
                    } else {
                        if ($this->apiKey != null && $this->apiKey->ignore_limits == true) {
                            // then we skip this
                        } else {

                            $methodIncrement = ( ! empty($limits['method']['increment'])) ? $limits['method']['increment'] : Config::get('apiguard.keyLimitIncrement', '1 hour');
                            $methodIncrementTime = strtotime('-' . $methodIncrement);

                            if ($methodIncrementTime == false) {
                                Log::warning("[Utkarshx/ApiGuard] You have specified an invalid method increment time. This value can be any value accepted by PHP's strtotime() method");
                            } else {
                                // Count the number of requests for this method
                                $apiLogCount = $apiLog->countMethodRequests(Route::currentRouteAction(), $request->getMethod(), $methodIncrementTime);

                                if ($apiLogCount >= $methodLimit) {
                                    Log::warning("[Utkarshx/ApiGuard] The API has reached the method limit of {$methodLimit} in the following route: " . Route::currentRouteAction());
                                    return $this->response->errorUnwillingToProcess('The limit for using this API method has been reached');
                                }
                            }
                        }
                    }
                }
            }

            // End of cheking limits
            if (Config::get('apiguard.logging', true)) {
                // Default to log requests from this action
                $logged = true;

                if (isset($apiMethods[$method]['logged']) && $apiMethods[$method]['logged'] === false) {
                    $logged = false;
                }

                if ($logged) {
                    // Log this API request
                    $this->apiLog = App::make(Config::get('apiguard.apiLogModel', 'Utkarshx\ApiGuard\Models\ApiLog'));

                    if (isset($this->apiKey)) {
                        $this->apiLog->api_key_id = $this->apiKey->id;
                    }

                    $this->apiLog->route      = Route::currentRouteAction();
                    $this->apiLog->method     = $request->getMethod();
                    $this->apiLog->params     = http_build_query(Input::all());
                    $this->apiLog->ip_address = $request->getClientIp();
                    $this->apiLog->save();

                }
            }
            $this->initialize();

        }, ['apiMethods' => $this->apiMethods]);
    }

    public function initialize()
    {
        // This is where you want to construct your class.. in replace of the constructor
    }

}
