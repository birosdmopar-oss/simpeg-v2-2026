<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Setup how the exception handler works.
 */
class Exceptions extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * LOG EXCEPTIONS?
     * --------------------------------------------------------------------------
     * If true, then exceptions will be logged
     * through Services::Log.
     *
     * Default: true
     */
    public bool $log = true;

    /**
     * --------------------------------------------------------------------------
     * DO NOT LOG STATUS CODES
     * --------------------------------------------------------------------------
     * Any status codes here will NOT be logged if logging is turned on.
     * By default, only 404 (Page Not Found) exceptions are ignored.
     *
     * @var list<int>
     */
    public array $ignoreCodes = [404];

    /**
     * --------------------------------------------------------------------------
     * Error Views Path
     * --------------------------------------------------------------------------
     * This is the path to the directory that contains the 'cli' and 'html'
     * directories that hold the views used to generate errors.
     *
     * Default: APPPATH.'Views/errors'
     */
    public string $errorViewPath = APPPATH . 'Views/errors';

    /**
     * --------------------------------------------------------------------------
     * HIDE FROM DEBUG TRACE
     * --------------------------------------------------------------------------
     * Any data that you would like to hide from the debug trace.
     * In order to specify 2 levels, use "/" to separate.
     * ex. ['server', 'setup/password', 'secret_token']
     *
     * @var list<string>
     */
    public array $sensitiveDataInTrace = [];

    /**
     * --------------------------------------------------------------------------
     * WHETHER TO THROW AN EXCEPTION ON DEPRECATED ERRORS
     * --------------------------------------------------------------------------
     * If set to `true`, DEPRECATED errors are only logged and no exceptions are
     * thrown. This option also works for user deprecations.
     */
    public bool $logDeprecations = true;

    /**
     * --------------------------------------------------------------------------
     * LOG LEVEL THRESHOLD FOR DEPRECATIONS
     * --------------------------------------------------------------------------
     * If `$logDeprecations` is set to `true`, this sets the log level
     * to which the deprecation will be logged. This should be one of the log
     * levels recognized by PSR-3.
     *
     * The related `Config\Logger::$threshold` should be adjusted, if needed,
     * to capture logging the deprecations.
     */
    public string $deprecationLogLevel = LogLevel::WARNING;

    /*
     * DEFINE THE HANDLERS USED
     * --------------------------------------------------------------------------
     * Given the HTTP status code, returns exception handler that
     * should be used to deal with this error. By default, it will run CodeIgniter's
     * default handler and display the error information in the expected format
     * for CLI, HTTP, or AJAX requests, as determined by is_cli() and the expected
     * response format.
     *
     * Custom handlers can be returned if you want to handle one or more specific
     * error codes yourself like:
     *
     *      if (in_array($statusCode, [400, 404, 500])) {
     *          return new \App\Libraries\MyExceptionHandler();
     *      }
     *      if ($exception instanceOf PageNotFoundException) {
     *          return new \App\Libraries\MyExceptionHandler();
     *      }
     */
    public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
    {
        // API (route kosong/berprefix api/ atau klien minta JSON) → envelope JSON (ADR-001/ADR-002).
        if (! is_cli() && self::isApiRequest()) {
            return new ApiExceptionHandler($this);
        }

        return new ExceptionHandler($this);
    }

    /**
     * Request API = route path kosong (healthcheck) atau berprefix `api/`, atau klien meminta JSON.
     *
     * Route path diambil dari IncomingRequest::getPath() (relatif baseURL, tanpa indexPage). Jangan pakai
     * getUri()->getPath(): selama Config\App::$indexPage = 'index.php' nilainya 'index.php/api/...', sehingga prefix
     * api/ tidak pernah cocok dan request API tanpa `Accept: application/json` jatuh ke handler bawaan CI4 — bukan
     * envelope, dan pesan yang memuat byte non-UTF-8 (segmen URI yang ditolak Router) berujung fatal error HTML (CR-007).
     *
     * @param RequestInterface|null $request null = service('request')
     */
    public static function isApiRequest(?RequestInterface $request = null): bool
    {
        try {
            $request ??= service('request');

            if ($request instanceof IncomingRequest) {
                $path = trim($request->getPath(), '/');

                if ($path === '' || str_starts_with($path, 'api/')) {
                    return true;
                }
            }

            return str_contains($request->getHeaderLine('Accept'), 'application/json');
        } catch (Throwable) {
            return false;
        }
    }
}
