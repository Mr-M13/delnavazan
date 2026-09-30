<?php $model = $args['model'] ?? array(); $announcement = $model['announcement'] ?? array(); $announcement_states = array( 'general', 'technical', 'reminder', 'none' ); $announcement_valid = in_array( $announcement['state'] ?? 'none', $announcement_states, true ); ?>
<?php if ( ! $announcement_valid ) : ?><aside class="dzn-tp-banner is-error"><strong>اعلان در دسترس نیست</strong><p>وضعیت اعلان شناخته نشد.</p></aside><?php elseif ( 'none' !== ( $announcement['state'] ?? 'none' ) ) : ?><aside class="dzn-tp-banner is-<?php echo esc_attr( $announcement['state'] ); ?>"><span aria-hidden="true">●</span><div><strong><?php echo esc_html( $announcement['title'] ?? '' ); ?></strong><p><?php echo esc_html( $announcement['message'] ?? '' ); ?></p></div></aside><?php endif; ?>
<?php dzn_theme_teacher_portal_component( 'attention', array( 'items' => $model['attention'] ?? array() ) ); ?>
<?php dzn_theme_teacher_portal_component( 'classes', array( 'items' => $model['classes'] ?? array() ) ); ?>
<?php dzn_theme_teacher_portal_component( 'calendar', array( 'items' => $model['calendar'] ?? array() ) ); ?>
<?php dzn_theme_teacher_portal_component( 'help' ); ?>
