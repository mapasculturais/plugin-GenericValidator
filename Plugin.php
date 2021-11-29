<?php

namespace GenericValidator;

use MapasCulturais\i;
use MapasCulturais\App;
use AbstractValidator\AbstractValidator;
use MapasCulturais\Entities\Registration;

require_once __DIR__ . "/../AbstractValidator/AbstractValidator.php";

class Plugin extends AbstractValidator
{
    protected static $instance = null;

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
            "export_fields" => [],

            // Configuração das manipulções dos status
            "enabled_alter_status_on_import" => false,

            // Valores para a inscrição homologada
            'result_homologated' => i::__('Homologada'),
            'obs_homologated' => i::__('Inscrição homologada'),
            'status_homologated' => Registration::STATUS_SENT,

            // Valores para a inscrição Em análise
            'result_analysis' => i::__('Inscrição em análise'),
            'obs_analysis' => i::__('Inscrição em análise'),
            'status_analysis' => Registration::STATUS_SENT,

            // Valores para a inscrição Selecionada
            'result_selected' => i::__('Selecionada'),
            'obs_selected' => i::__('Inscrição selecionada'),
            'status_selected' => Registration::STATUS_APPROVED,

            // Valores para a inscrição inválida
            'result_invalid' => i::__('Inválida'),
            'obs_invalid' => i::__('Inscrição inválida'),
            'status_invalid' => Registration::STATUS_INVALID,

            // Valores para a inscrição Não selecionada
            'result_not_selected' => i::__('Não selecionada'),
            'obs_not_selected' => i::__('Inscrição não selecionada'),
            'status_not_selected' => Registration::STATUS_NOTAPPROVED,

            // Valores para a inscrição suplente
            'result_substitute' => i::__('Suplente'),
            'obs_substitute' => i::__('Recurso: inscrição suplente'),
            'status_substitute' => Registration::STATUS_WAITLIST
        ];
        $this->_config = $config;
        parent::__construct($config);
        self::$instance[$config["slug"]] = $this;
        return;
    }

    function _init()
    {
        $app = App::i();
        $plugin = $this;
       
        // Botões de upload e download das planilhas
        $app->hook("template(opportunity.<<single|edit>>.sidebar-right):end", function () use ($plugin) {
            
            /** @var \MapasCulturais\Theme $this */
            $opportunity = $this->controller->requestedEntity;
            
            $is_opportunity_managed = $plugin->config["is_opportunity_managed_handler"]($opportunity);

            if ($is_opportunity_managed && $opportunity->canUser("@control")) {
                $this->part("validator/validator-uploads", [
                    "entity" => $opportunity,
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

    public static function getInstanceBySlug(string $slug)
    {
        return (self::$instance[$slug] ?? null);
    }

    public function prefix(string $value): string
    {
        return ($this->getSlug() . "_$value");
    }

    function getName(): string
    {
        return $this->_config["name"];
    }

    function getSlug(): string
    {
        return $this->_config["slug"];
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
