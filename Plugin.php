<?php

namespace GenericValidator;

use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\Entities\Registration;

class Plugin extends \AbstractValidator\AbstractValidator
{
    function __construct(array $config=[])
    {
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
            "export_fields" => [i::__("ID do Agente") => "agent_id"],
        ];
        $this->_config = $config;
        parent::__construct($config);
        return;
    }

    function _init()
    {
        $app = App::i();
        $plugin_slo = $app->plugins[$this->config["slo_id"]];
        $plugin = $this;
        $opportunity_id = $plugin_slo->managedOpportunity;
        //botao de export csv
        $app->hook("template(opportunity.single.header-inscritos):end", function () use ($plugin_slo, $plugin, $app, $opportunity_id) {
            /** @var \MapasCulturais\Theme $this */
            $requestedOpportunity = $this->controller->requestedEntity;
            if (($requestedOpportunity->id == $opportunity_id) && $requestedOpportunity->canUser("@control")) {
                $app->view->enqueueScript("app", "streamlinedopportunity", "streamlinedopportunity/app.js");
                $this->part("validator/csv-button", [
                    "opportunity" => $opportunity_id,
                    "plugin_slo" => $plugin_slo,
                    "plugin" => $plugin
                ]);
            }
            return;
        });
        // uploads de CSVs
        $app->hook("template(opportunity.<<single|edit>>.sidebar-right):end", function () use ($plugin_slo, $plugin, $opportunity_id) {
            /** @var \MapasCulturais\Theme $this */
            $opportunity = $this->controller->requestedEntity;
            if (($opportunity->id == $opportunity_id) && $opportunity->canUser("@control")) {
                $this->part("validator/validator-uploads", [
                    "entity" => $opportunity,
                    "plugin_slo" => $plugin_slo,
                    "plugin" => $plugin
                ]);
            }
            return;
        });
        parent::_init();
        return;
    }

    function register()
    {
        $app = App::i();
        $slug = $this->getSlug();
        $this->registerOpportunityMetadata($slug . "_processed_files", [
            "label" => "Arquivos do validador processados",
            "type" => "json",
            "private" => true,
            "default_value" => "{}"
        ]);
        $this->registerRegistrationMetadata($slug . "_filename", [
            "label" => "Nome do arquivo do validador",
            "type" => "string",
            "private" => true,
        ]);
        $this->registerRegistrationMetadata($slug . "_raw", [
            "label" => "Dados não processados do validador (linha do csv)",
            "type" => "json",
            "private" => true,
            "default_value" => "{}"
        ]);
        $this->registerRegistrationMetadata($slug . "_processed", [
            "label" => "Dados processados do validador",
            "type" => "json",
            "private" => true,
            "default_value" => "{}"
        ]);
        $definition = new \MapasCulturais\Definitions\FileGroup($slug, ["^text/csv$"], i::__("O arquivo enviado não é um csv."), false, null, true);
        $app->registerFileGroup("opportunity", $definition);
        parent::register();
        $app->controller($slug)->plugin = $this;
        return;
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