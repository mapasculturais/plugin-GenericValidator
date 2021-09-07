<?php

namespace GenericValidator;

use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use StreamlinedOpportunity\Plugin as StreamlinedOpportunity;

class Plugin extends \AbstractValidator\AbstractValidator
{
    protected static $instance = null;
    
    function __construct(array $config=[])
    {
        self::$instance =  $this;

        $config += [
            // slug utilizado como id do controller
            "slug" => "generic-validator",
            // nome apresentado na interface
            "name" => "Validador Genérico",
            // se true, só considera a validação deste validador na consolidação
            "is_absolute" => false,
            // se true, só consolida se houver ao menos uma homologação
            "homologation_required" => true,
            // se true, só exporta se houver ao menos uma homologação
            "homologation_required_for_export" => true,
            // callback para determinar se a oportunidade é gerenciada (pelo plugin StreamlinedOpportunity)
            "is_opportunity_managed_handler" => function ($opportunity) { return false; },
            // lista de validadores requeridos na consolidação
            "required_validations" => [],
            // lista de validadores requeridos na exportação
            "required_validations_for_export" => [],
            // campos do exportador
            "export_fields" => [i::__("DOCUMENTO") => function($registration){
                return $registration->owner->documento;
            }],
        ];
        $this->_config = $config;
        parent::__construct($config);
        return;
    }

    function _init()
    {
        $app = App::i();
        $plugin = $this;

        //botao de export csv
        $app->hook("template(opportunity.single.header-inscritos):end", function () use ($plugin, $app) {
            /** @var \MapasCulturais\Theme $this */
            
            $opportunity = $this->controller->requestedEntity;
            $is_opportunity_managed_handler = $plugin->config['is_opportunity_managed_handler']($opportunity);
            
            if($is_opportunity_managed_handler && $opportunity->canUser('@control')) {

                $slo_instance = StreamlinedOpportunity::getInstanceByOpportunityId($opportunity->id);

                $app->view->enqueueScript("app", "streamlinedopportunity", "streamlinedopportunity/app.js");
                $this->part("validator/csv-button", [
                    "opportunity" => $opportunity->id,
                    "slo_instance" => $slo_instance,
                    "plugin" => $plugin
                ]);
            }
        });

        // uploads de CSVs
        $app->hook("template(opportunity.<<single|edit>>.sidebar-right):end", function () use ($plugin) {
            /** @var \MapasCulturais\Theme $this */
            $opportunity = $this->controller->requestedEntity;
            $is_opportunity_managed_handler = $plugin->config['is_opportunity_managed_handler']($opportunity);
            
            if($is_opportunity_managed_handler && $opportunity->canUser('@control')) {

                $slo_instance = StreamlinedOpportunity::getInstanceByOpportunityId($opportunity->id);

                $this->part("validator/validator-uploads", [
                    "entity" => $opportunity,
                    "plugin_slo" => $slo_instance,
                    "plugin" => $plugin
                ]);
            }
        });
        parent::_init();
        return;
    }

    function register()
    {
        $app = App::i();
        $this->registerOpportunityMetadata($this->prefix("processed_files"), [
            "label" => "Arquivos do validador processados",
            "type" => "json",
            "private" => true,
            "default_value" => "{}"
        ]);
        $this->registerRegistrationMetadata($this->prefix("filename"), [
            "label" => "Nome do arquivo do validador",
            "type" => "string",
            "private" => true,
        ]);
        $this->registerRegistrationMetadata($this->prefix("raw"), [
            "label" => "Dados não processados do validador (linha do csv)",
            "type" => "json",
            "private" => true,
            "default_value" => "{}"
        ]);
        $this->registerRegistrationMetadata($this->prefix("processed"), [
            "label" => "Dados processados do validador",
            "type" => "json",
            "private" => true,
            "default_value" => "{}"
        ]);

        $slug = $this->getSlug();
        $definition = new \MapasCulturais\Definitions\FileGroup($slug, ["^text/csv$"], i::__("O arquivo enviado não é um csv."), false, null, true);
        $app->registerFileGroup("opportunity", $definition);
        parent::register();
        // $app->controller($slug)->plugin = $this;
        return;
    }

    public static function getInstance()
    {
        return  self::$instance;
    }

    public function prefix(string $value): string
    {
        return $this->getSlug()."_$value";
    }

    function getName(): string
    {
        return $this->_config["name"];
    }

    function getSlug(): string
    {
        return $this->_config["slug"];
    }

    function getStreamlinedPlugin(): \MapasCulturais\Plugin
    {
        return App::i()->plugins[$this->config["slo_id"]];
    }

    function getControllerClassname(): string
    {
        return Controller::class;
    }

    function isRegistrationEligible(Registration $registration): bool
    {
        return true;
    }
}
