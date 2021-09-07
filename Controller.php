<?php

namespace GenericValidator;

use DateTime;
use Doctrine\ORM\ORMException;
use Exception;
use InvalidArgumentException;
use League\Csv\Writer;
use League\Csv\Reader;
use League\Csv\Statement;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\i;
use GenericValidator\Plugin as GenericValidator;

/**
 * Generic Validator Controller
 *
 *  @property-read \MapasCulturais\Entities\Registration $requestedEntity The Requested Entity
 */
class Controller extends \MapasCulturais\Controllers\Registration
{
    protected $config = [];
    protected $plugin;

    protected $_initiated = false;


    /**
     * Retorna uma instância do controller
     * @param string $controller_id 
     * @return StreamlinedOpportunity 
     */
    static public function i(string $controller_id): Controller {
        $instance = parent::i($controller_id);
        $instance->init($controller_id);

        return $instance;
    }

    protected function init($controller_id) {
        if (!$this->_initiated) {
            $this->plugin = Plugin::getInstanceBySlug($controller_id);
            $this->config = $this->plugin->config;

            $this->_initiated = true;
        }
    }

    protected function exportInit(Opportunity $opportunity)
    {
        $this->requireAuthentication();
        if (!$opportunity->canUser("@control")) {
            echo i::__("Não autorizado");
            die();
        }
        $this->registerRegistrationMetadata($opportunity);
        // sets timeout and memory limit to sufficiently generous values
        ini_set("max_execution_time", 0);
        ini_set("memory_limit", "768M");
        return;
    }

    /**
     * Retrieve the registrations.
     * @param Opportunity $opportunity
     * @return Registration[]
     */
    protected function getRegistrations(Opportunity $opportunity)
    {
        $app = App::i();

        $plugin = $this->plugin;

        // registration status
        $status = intval($this->data["status"] ?? 1);
        $dql_params = [
            "opportunity_id" => $opportunity->id,
            "status" => $status,
        ];
        $from = $this->data["from"] ?? "";
        $to = $this->data["to"] ?? "";
        
        if ($from && !DateTime::createFromFormat("Y-m-d", $from)) {
            throw new \Exception(i::__("O formato do parâmetro `from` é inválido."));
        }
        if ($to && !DateTime::createFromFormat("Y-m-d", $to)) {
            throw new \Exception(i::__("O formato do parâmetro `to` é inválido."));
        }
        $dql_from = "";

      
        if ($from) { // start date
            $dql_params["from"] = (new DateTime($from))->format("Y-m-d 00:00");
            $dql_from = "e.sentTimestamp >= :from AND";
        }
        $dql_to = "";
        if ($to) { // end date
            $dql_params["to"] = (new DateTime($to))->format("Y-m-d 00:00");
            $dql_to = "e.sentTimestamp <= :to AND";
        }
        $dql = "
            SELECT
                e
            FROM
                MapasCulturais\Entities\Registration e
            WHERE
                $dql_to
                $dql_from
                e.status = :status AND
                e.opportunity = :opportunity_id";
        $query = $app->em->createQuery($dql);
        $query->setParameters($dql_params);
        $result = $query->getResult();

        // exclude registrations that have not been homologated, or were previously validated, or are missing requirements
        $registrations = [];
        $repo = $app->repo("RegistrationEvaluation");
        $validator_user = $plugin->user;
        foreach ($result as $registration) {
            $evaluations = $repo->findBy([
                "registration" => $registration,
                "status" => Registration::STATUS_ENABLED
            ]);
            $eligible = true;
            // previously validated registrations
            foreach ($evaluations as $evaluation) {
                if ($validator_user->equals($evaluation->user)) {
                    $eligible = false;
                    break;
                }
            }
            if (!$eligible) {
                continue;
            }
             // homologated registrations (depending on configuration)
             /** @todo: handle other evaluation methods */
            if ($this->config["homologation_required_for_export"]) {
                $homologated = false;
                // find a "selected" evaluation (result "10"), but keep an eye out for "non-selected" evaluations, these take priority
                foreach ($evaluations as $evaluation) {
                    if ($evaluation->user->validator_for) {
                        continue;
                    }
                    if ($evaluation->result == "10") {
                        $homologated = true;
                    } else {
                        $homologated = false;
                        break;
                    }
                }
                if (!$homologated) {
                    $eligible = false;
                }
            }
            if (!$eligible) {
                continue;
            }
            // registrations meeting the configured validation requirements
            /** @todo: handle other evaluation methods */
            foreach ($this->config["required_validations_for_export"] as $slug) {
                $validated = false;
                foreach ($evaluations as $evaluation) {
                    if (($evaluation->user->validator_for == $slug) && ($evaluation->result == "10")) {
                        $validated = true;
                        break;
                    }
                }
                if (!$validated) {
                    $eligible = false;
                    break;
                }
            }
            if ($eligible) {
                $registrations[] = $registration;
            }
        }
        $app->applyHookBoundTo($this, "validator({$plugin->slug}).registrations", [&$registrations, $opportunity]);
        return $registrations;
    }

    protected function generateCSV(string $prefix, array $registrations, array $fields): string
    {
        // CSV header array
        /** @var array $headers */
        $headers = array_merge([i::__("NUMERO")], array_keys($fields), [i::__("AVALIACAO"), i::__("OBSERVACOES")]);

        
        $csv_data = [];
        foreach ($registrations as $i => $registration) {
            $csv_data[$i] = [i::__("NUMERO") => $registration->number];
            foreach ($fields as $key => $field) {
                if (is_callable($field)) {
                    $value = $field($registration, $key);
                } else if (is_string($field)) {
                    $value = $registration->$field;
                } else if (is_int($field)) {
                    $field = "field_{$field}";
                    $value = $registration->$field;
                } else {
                    $value = $field;
                }
                if (is_array($value)) {
                    $value = implode(",", $value);
                }
                $csv_data[$i][$key] = $value;
            }
        }
        
        $slug = $this->plugin->slug;
        $hash = md5(json_encode($csv_data));
        $dir = PRIVATE_FILES_PATH . $this->data['slo_slug'] . '/';
        $filename =  $dir . "{$slug}-{$prefix}-{$hash}.csv";
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stream = fopen($filename, "w");
        $csv = Writer::createFromStream($stream);
        $csv->setDelimiter(";");
        $csv->insertOne($headers);
        foreach ($csv_data as $csv_line) {
            $csv->insertOne($csv_line);
        }
        return $filename;
    }

    /**
     * Exporter
     *
     * Generates a CSV with registration data according to export configuration and parameters.
     * http://localhost:8080/{$slug}/export/status:1/from:2020-01-01/to:2020-01-30
     *
     * All parameters are optional, the date range defaults to the entire history and status defaults to 1.
     */
    public function ALL_export()
    {
        $app = App::i();

        $opportunity_id = $this->data['opportunity'];
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);
        
        $this->exportInit($opportunity);
     
        $registrations = $this->getRegistrations($opportunity);

        if (empty($registrations)) {
            echo "No registrations found.";
            die();
        }
        $fields = $this->config["export_fields"];
        $filename = $this->generateCSV("op{$opportunity->id}-registrations", $registrations, $fields);
        header("Content-Type: application/csv");
        header("Content-Disposition: attachment; filename=" . basename($filename));
        header("Pragma: no-cache");
        readfile($filename);
        return;
    }

    public function GET_import()
    {
        $this->requireAuthentication();

        $app = App::i();
        $opportunity_id = $this->data["opportunity"] ?? 0;
        $file_id = $this->data["file"] ?? 0;
        $opportunity = $app->repo("Opportunity")->find($opportunity_id);
        if (!$opportunity) {
            echo i::__("Opportunity with ID $opportunity_id was not found");
            die();
        }
        
        $opportunity->checkPermission("@control");
        $files = $opportunity->getFiles($this->plugin->slug);
        foreach ($files as $file) {
            if ($file->id == $file_id) {
                $this->import($opportunity, $file->getPath());
            }
        }
        
        return;
    }

    /**
     * Data importer
     *
     * http://localhost:8080/{slug}/import/
     */
    public function import(Opportunity $opportunity, string $filename)
    {
        // sets timeout and memory limit to sufficiently generous values
        ini_set("max_execution_time", 0);
        ini_set("memory_limit", "768M");
        // check that the file exists
        if (!file_exists($filename)) {
            throw new Exception("Error importing data. File not found on the server.");
        }
        // check that file has the correct extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext != "csv") {
            throw new Exception("File type is not allowed.");
        }
        $app = App::i();

        $plugin = $this->plugin;

        // setup CSV reader
        $stream = fopen($filename, "r");
        $csv = Reader::createFromStream($stream);
        $csv->setDelimiter(";");
        // extract header
        $header_temp = $csv->setHeaderOffset(0);
        $stmt = new Statement();
        $results = $stmt->process($csv);
        $header_file = [];
        foreach ($header_temp as $key => $value) {
            $header_file[] = $value;
            break;
        }
        // check that required columns exist
        $key_num = i::__("NUMERO");
        $key_eval = i::__("AVALIACAO");
        $key_notes = i::__("OBSERVACOES");
        if (!isset($header_file[0][$key_num]) || !isset($header_file[0][$key_eval]) || !isset($header_file[0][$key_notes])) {
            die("Columns $key_num, $key_eval, and $key_notes are mandatory.");
        }
        $slug = $plugin->slug;
        $name = $plugin->name;
        $app->disableAccessControl();
        $count = 0;
        foreach ($results as $i => $line) {
            $count++;
            $num = $line[$key_num];
            $obs = $line[$key_notes];
            $eval = $line[$key_eval];
            // @TODO: Are these subject to i18n?
            switch (strtolower($eval)) {
                case "selecionado":
                case "selecionada":
                    $result = "10";
                    break;
                case "invalido":
                case "inválido":
                case "invalida":
                case "inválida":
                    $result = "2";
                    break;
                case "não selecionado":
                case "nao selecionado":
                case "não selecionada":
                case "nao selecionada":
                    $result = "3";
                    break;
                case "suplente":
                    $result = "8";
                    break;
                default:
                    die("The value for column $key_eval at line $i is invalid. Allowed values are 'selecionada', 'invalida', 'nao selecionada', and 'suplente'.");
            }
            $registration = $app->repo("Registration")->findOneBy(["number" => $num]);
            $registration->__skipQueuingPCacheRecreation = true;
            /* @TODO: implementar atualização de status?? */
            if ($registration->{$slug . "_raw"} != (object) []) {
                $app->log->info("$name #{$count} opportunity/{$registration->opportunity->id} #{$registration->number} $eval - ALREADY PROCESSED");
                continue;
            }
            $app->log->info("$name #{$count} {$registration} $eval");
            $registration->{$slug . "_raw"} = $line;
            $registration->{$slug . "_filename"} = $filename;
            $registration->save(true);
            $user = $plugin->user;
            /* @TODO: versão para avaliação documental */
            $evaluation = new RegistrationEvaluation;
            $evaluation->__skipQueuingPCacheRecreation = true;
            $evaluation->user = $user;
            $evaluation->registration = $registration;
            if ($opportunity->getEvaluationMethod()->slug == "documentary") {
                $evaluation->result = ($result == "10") ? 1 : -1;
                $evaluation->evaluationData = [$eval => $obs];
            } else {
                $evaluation->result = $result;
                $evaluation->evaluationData = ["status" => $result, "obs" => $obs];
            }
            $evaluation->status = 1;
            $evaluation->save(true);
            $app->em->clear();
        }
        $app->enableAccessControl();
        // the entity must be fetched again for writing due to $app->em->clear()
        $opportunity = $app->repo("Opportunity")->find($opportunity->id);
        $slug = $plugin->slug;
        $opportunity->refresh();
        $opportunity->name = $opportunity->name . " ";
        $files = $opportunity->{$slug . "_processed_files"};
        $files->{basename($filename)} = date("d/m/Y \à\s H:i");
        $opportunity->{$slug . "_processed_files"} = $files;
        $opportunity->save(true);
        $this->finish("ok");
        return;
    }
}
