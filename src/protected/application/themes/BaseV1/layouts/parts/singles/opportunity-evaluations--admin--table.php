<header id="header-inscritos" class="clearfix">
    <a href="<?php echo $this->controller->createUrl('reportEvaluations', [$entity->id]) ?>" class="btn btn-default alignright download"><?php \MapasCulturais\i::_e('Baixar lista de avaliações') ?></a>
    <h3><?php \MapasCulturais\i::_e("Avaliações");?></h3>
    <!--<a class="btn btn-default download" href="<?php echo $this->controller->createUrl('report', [$entity->id]); ?>"><?php \MapasCulturais\i::_e("Baixar lista de avaliações");?></a>-->
</header>
<table class="js-registration-list registrations-table" ng-class="{'no-options': data.entity.registrationCategories.length === 0, 'no-attachments': data.entity.registrationFileConfigurations.length === 0, 'registrations-results': data.entity.published}"><!-- adicionar a classe registrations-results quando resultados publicados-->
    <thead>
        <tr>
            <th class="registration-id-col">
                <?php \MapasCulturais\i::_e("Inscrição");?>
            </th>
            <th class="registration-id-col">
                <mc-select placeholder="<?php \MapasCulturais\i::esc_attr_e("Avaliador"); ?>" model="evaluationsFilters['valuer:id']" data="data.evaluationCommittee"></mc-select>
            </th>
            <th ng-if="data.entity.registrationCategories" class="registration-option-col">
                <mc-select placeholder="<?php \MapasCulturais\i::esc_attr_e("Categoria"); ?>" model="evaluationsFilters['registration:category']" data="data.registrationCategoriesToFilter"></mc-select>
            </th>
            <th class="registration-agents-col">
                <?php \MapasCulturais\i::_e("Agente Responsável");?>
            </th>
            <th class="registration-status-col">
                <?php \MapasCulturais\i::_e("Avaliação");?>
            </th>
            <th class="registration-status-col">
                <mc-select placeholder="<?php \MapasCulturais\i::esc_attr_e("Status"); ?>" model="evaluationsFilters['status']" data="data.evaluationStatuses"></mc-select>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td colspan='5'>
                <span ng-if="!usingFilters() && data.evaluationsAPIMetadata.count === 0"><?php \MapasCulturais\i::_e("Nenhuma avaliação enviada.");?></span>
                <span ng-if="usingFilters() && data.evaluationsAPIMetadata.count === 0"><?php \MapasCulturais\i::_e("Nenhuma avaliação encontrada com os filtros selecionados.");?></span>
                <span ng-if="!usingFilters() && data.evaluationsAPIMetadata.count === 1"><?php \MapasCulturais\i::_e("1 avaliação enviada.");?></span>
                <span ng-if="usingFilters() && data.evaluationsAPIMetadata.count === 1"><?php \MapasCulturais\i::_e("1 avaliação encontrada com os filtros selecionados.");?></span>
                <span ng-if="!usingFilters() && data.evaluationsAPIMetadata.count > 1">{{data.opportunityEvaluations.length}} <?php \MapasCulturais\i::_e("Avaliações.");?>
                    <?php if($entity->registrationLimit > 0):?>
                         | <?php \MapasCulturais\i::_e("Número máximo de vagas na oportunidade:");?> <?php echo $entity->registrationLimit;?>
                    <?php endif;?>
                </span>
                <span ng-if="usingFilters() && data.evaluationsAPIMetadata.count > 1">{{data.evaluationsAPIMetadata.count}} <?php \MapasCulturais\i::_e("inscrições encontradas com os filtros selecionados.");?></span>
            </td>
        </tr>

        <tr ng-repeat="evaluation in data.evaluations" id="registration-{{evaluation.registration.id}}" >
            <td class="registration-id-col">
                <a href='{{evaluation.evaluation.singleUrl}}'>
                    <strong>{{evaluation.registration.number}}</strong>
                </a>
            </td>
            <td class="registration-id-col">{{evaluation.valuer.name}}</td>
            <td ng-if="data.entity.registrationCategories" class="registration-option-col">{{evaluation.registration.category}}</td>
            <td class="registration-agents-col">
                <p>
                    <span class="label"><?php \MapasCulturais\i::_e("Responsável");?></span><br />
                    <a href="{{evaluation.registration.owner.singleUrl}}">{{evaluation.registration.owner.name}}</a>
                </p>
            </td>
            <td class="registration-status-col">
                {{getEvaluationResultString(evaluation)}}
            </td>
            <td class="registration-status-col">
                <strong>{{getEvaluationStatusLabel(evaluation)}}</strong>
            </td>
        </tr>
    </tbody>
</table>
