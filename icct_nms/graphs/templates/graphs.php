<?php /** Native graph template associations, without graph image previews. */ ?>
<section
    id="device-graphs"
    class="graphs-page"
    <?= (isset($initialStep) && $initialStep === 'graphs') ? '' : 'hidden' ?>>
    <div class="graph-add-control">
        <button
            type="button"
            class="add-graph-button"
            aria-expanded="false"
            aria-controls="graph-add-panel">+ Add Graph</button>
        <div
            id="graph-add-panel"
            hidden>
            <form
                method="post"
                class="graph-template-add">
                <?php icct_nms_token(); ?>
                <input
                    type="hidden"
                    name="action"
                    value="add_graph_template" />
                <label class="field">
                    <span
                        class="field-label"
                        data-field-help="Choose an installed Cacti graph template to associate with this device.">Add Graph Template</span>
                    <span class="select-wrap">
                        <select
                            name="graph_template_id"
                            data-searchable-template="Graph Template"
                            required
                            <?= $availableGraphTemplates ? '' : 'disabled' ?>>
                            <option value="">Select Graph Template</option>
                            <?php foreach (
                                $availableGraphTemplates as $template
                            ): ?>
                                <option value="<?= (int) $template['id'] ?>"><?= icct_nms_h($template['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                </label>
                <button
                    class="button primary"
                    <?= $availableGraphTemplates ? '' : 'disabled' ?>>Add</button>
            </form>
        </div>
    </div>
    <div class="graph-accordion-list">
        <?php foreach ($graphAssociations as $index => $template):
            $origin = icct_nms_graph_origin((int)$template['id']);
            ?>
            <details
                class="graph-association"
                data-graph-template-id="<?= (int) $template['id'] ?>">
                <summary>
                    <span class="graph-association-name">
                        <span class="graph-association-number"><?= (int) $index + 1 ?>)</span>
                        <span><?= icct_nms_h($template['name']) ?></span>
                    </span>
                    <span class="graph-badges">
                        <?php if (!empty($template['device_template_name'])): ?>
                            <span class="graph-badge"><?= icct_nms_h($template['device_template_name']) ?></span>
                        <?php endif; ?>
                        <?php
                        $originLabel = is_array($origin) ? ($origin['label'] ?? 'Native') : (string) $origin;
                        $originEdited = is_array($origin) && !empty($origin['edited']);
                        ?>
                        <span class="graph-badge"><?= icct_nms_h($originLabel) ?><?php if ($originEdited): ?> (Edited)<?php endif; ?></span>
                    </span>
                    <form
                        method="post"
                        data-remove-graph-template
                        data-template-name="<?= icct_nms_h($template['name']) ?>">
                        <?php icct_nms_token(); ?>
                        <input
                            type="hidden"
                            name="action"
                            value="remove_graph_template" />
                        <input
                            type="hidden"
                            name="graph_template_id"
                            value="<?= (int) $template['id'] ?>" />
                        <button
                            class="icon-button"
                            type="submit"
                            aria-label="Remove <?= icct_nms_h($template['name']) ?> association"
                            data-tooltip="Remove this template association. Existing graphs and data remain saved.">
                            <svg
                                viewBox="0 0 24 24"
                                aria-hidden="true">
                                <path d="M5 6h14M9 6V3h6v3M7 6l1 15h8l1-15M10 10v7M14 10v7" />
                            </svg>
                        </button>
                    </form>
                </summary>
                <div class="graph-association-content">
                    <?php
                    $detailsList = !empty($template['details']) ? $template['details'] : (!empty($template['parameters']) ? [$template['parameters']] : []);
                    foreach ($detailsList as $detail):
                        if (!is_array($detail)) {
                            continue;
                        }
                        $graphSettings = !empty($detail['settings']) && is_array($detail['settings']) ? $detail['settings'] : [];
                        $graphItems = !empty($detail['items']) && is_array($detail['items']) ? $detail['items'] : [];
                    ?>
                        <h3><?= icct_nms_h(
                            $graphSettings['title_cache'] ?? '' ?: $graphSettings['title'] ?? $template['name'],
                        ) ?></h3>
                        <?php include __DIR__ . '/graph_parameters.php'; ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if (!$graphAssociations): ?>
            <p class="empty-state">No associated graph templates.</p>
        <?php endif; ?>
    </div>
</section>
