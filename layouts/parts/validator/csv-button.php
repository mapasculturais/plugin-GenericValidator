<?php
use MapasCulturais\i;

$app = MapasCulturais\App::i();

$slo_slug = $slo_instance->config['slug'];
$slug = $plugin->getSlug();
$name = $plugin->getName();

$route = MapasCulturais\App::i()->createUrl($slug, "export", ['opportunity' => $opportunity, 'slo_slug' => $slo_slug]);

?>
<a class="btn btn-default download btn-export-cancel"  ng-click="editbox.open('<?= $slug ?>-export', $event)" rel="noopener noreferrer">CSV <?= $name ?></a>
<!-- Formulário -->
<edit-box id="<?= $slug ?>-export" position="top" title="CSV <?=$name?>" cancel-label="Cancelar" close-on-cancel="true">
    <form class="form-export-<?= $slug ?>" action="<?=$route?>" method="POST">
        <label for="from">Data inicial</label>
        <input type="date" name="from" id="from">
        <label for="to">Data final</label>
        <input type="date" name="to" id="to">
        # Caso não queira filtrar entre datas, deixe os campos vazios.
        <button class="btn btn-primary download" type="submit">Exportar</button>
    </form>
</edit-box>
