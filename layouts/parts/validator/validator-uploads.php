<?php
use \MapasCulturais\i;

$app = MapasCulturais\App::i();
$slug = $plugin->getSlug();
$name = $plugin->getName();
$files = $entity->getFiles($slug);
$url = $app->createUrl($slug, "import", ["opportunity" => $entity->id]);
$msg_delete = i::__("Remover este arquivo?");
$btn_delete = i::__("Excluir arquivo");
$template = '
<li id="file-{{id}}" class="widget-list-item">
    <a href="{{url}}" rel="noopener noreferrer">{{description}}</a>
    <div class="botoes">
        <a href="' . $url . '?file={{id}}" class="btn btn-primary hltip js-validador-process" data-hltip-classes="hltip-ajuda" title="Clique para processar o arquivo enviado">processar</a>
        <a data-href="{{deleteUrl}}" data-target="#file-{{id}}" data-configm-message="' . $msg_delete . '" class="icon icon-close hltip js-remove-item" data-hltip-classes="hltip-ajuda" title="' . $btn_delete . '" rel="noopener noreferrer"></a>
    </div>
</li>';
?>
<div class="widget">
    <h3 class="editando"><?= sprintf(\MapasCulturais\i::__("Arquivos do %s"), $name) ?></h3>
    <div>
        <a class="add js-open-editbox hltip" data-target="#editbox-<?= $slug ?>-file" href="#" title="<?= sprintf(\MapasCulturais\i::__("Clique para adicionar subir novo arquivo de validação do %s"), $name) ?>"> <?= i::__("subir arquivo") ?></a>
    </div>
    <div id="editbox-<?= $slug ?>-file" class="js-editbox mc-left" title="<?= sprintf(\MapasCulturais\i::__("Subir arquivo de validação do %s"), $name) ?>" data-submit-label="<?= i::__("Enviar") ?>">
        <?php $this->ajaxUploader($entity, $slug, "append", "ul.js-validador", $template, "", false, false, false); ?>
    </div>
    <ul class="widget-list js-validador js-slimScroll">
        <?php if (is_array($files)): foreach( $files as $file): ?>
            <li id="file-<?= $file->id ?>" class="widget-list-item<?php if ($this->isEditable()) echo i::__(" is-editable"); ?>" >
                <a href="<?= $file->url ?>"><span><?= $file->description ? $file->description : $file->name ?></span></a>
                <?php if ($processed_at = $entity->{$slug . '_processed_files'}->{$file->name} ?? null): ?>
                    - <?= sprintf(\MapasCulturais\i::__("processado em %s"), $processed_at) ?>
                <?php else: ?>
                <div class="botoes">
                    <a href="<?=$url?>?file=<?=$file->id?>" class="btn btn-primary hltip js-validador-process" data-hltip-classes="hltip-ajuda" title="<?= i::__("Clique para processar o arquivo enviado") ?>">
                        <?= i::__("processar") ?>
                    </a>
                    
                    <a data-href="<?= $file->deleteUrl ?>" data-target="#file-<?= $file->id ?>" data-configm-message="<?= i::__("Remover este arquivo?") ?>" class="delete hltip js-remove-item" data-hltip-classes="hltip-ajuda" title="<?= i::__("Excluir arquivo. Só é possível fazer esta ação antes do processamento.") ?>"></a>
                </div>
                <?php endif; ?>
            </li>
        <?php endforeach; endif;?>
    </ul>
</div>
