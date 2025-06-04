 <?php
    require_once __DIR__ . '/../request_handler.php';

    // Fetch states using the RequestHandler
    $statesResult = RequestHandler::getStatesData();
    $entities = [];
    $error = null;

    if ($statesResult['success']) {
        $entities = $statesResult['data'];
        // Sort entities by domain for better organization
        usort($entities, function ($a, $b) {
            $domainA = explode('.', $a['entity_id'])[0];
            $domainB = explode('.', $b['entity_id'])[0];
            if ($domainA === $domainB) {
                return strcmp($a['entity_id'], $b['entity_id']);
            }
            return strcmp($domainA, $domainB);
        });
    } else {
        $error = $statesResult['error'];
    }
    ?>
 <!-- <script src="../js/websocket-handler.js"></script> -->
 <script>
     document.addEventListener("DOMContentLoaded", function() {
         // Initialize details state from localStorage
         initializeDetailsState();
         const wsHandler = new WebSocketHandler('<?= $_SESSION["ha_url"] ?>', '<?= $_SESSION["ha_token"] ?>');
         wsHandler.connect();

         // Subscribe to state changes for all entities after DOM is loaded
         setTimeout(() => {
             const entityCards = document.querySelectorAll('[data-entity-id]');
             const entityIds = Array.from(entityCards).map(card => card.dataset.entityId);

             console.log('Subscribing to entities:', entityIds);

             wsHandler.subscribeToStates(entityIds, (stateData) => {
                 console.log('State update received:', stateData);
                 updateEntityState(stateData);
             });
         }, 1000); // Wait for WebSocket connection to be established

         if (window.history.replaceState) {
             window.history.replaceState(null, null, window.location.href);
         }
     });

     // Function to update entity state in real-time
     function updateEntityState(stateData) {
         const entityCard = document.querySelector(`[data-entity-id="${stateData.entity_id}"]`);
         if (!entityCard) return;

         const stateElement = entityCard.querySelector('.ha-entity-state');
         const timeElement = entityCard.querySelector('.ha-entity-time');

         if (stateElement) {
             // Update state text
             stateElement.textContent = stateData.new_state.state;

             // Update CSS classes for visual indication
             stateElement.className = stateElement.className.replace(/state-(on|off|other)/g, '');
             if (stateData.new_state.state === 'on') {
                 stateElement.classList.add('state-on');
             } else if (stateData.new_state.state === 'off') {
                 stateElement.classList.add('state-off');
             } else {
                 stateElement.classList.add('state-other');
             }
         }

         if (timeElement && stateData.new_state.last_changed) {
             // Update timestamp
             const lastChanged = new Date(stateData.new_state.last_changed);
             timeElement.textContent = `Changed: ${lastChanged.toLocaleDateString('en-US', {
                 month: 'short',
                 day: 'numeric',
                 hour: 'numeric',
                 minute: '2-digit',
                 hour12: true
             })}`;
         }

         // Add visual feedback for the update
         entityCard.style.transition = 'background-color 0.3s ease';
         entityCard.style.backgroundColor = '#e8f5e8';
         setTimeout(() => {
             entityCard.style.backgroundColor = '';
         }, 1000);
     }

     // Initialize details state from localStorage
     function initializeDetailsState() {
         const savedState = localStorage.getItem('ha_details_state');
         const detailsState = savedState ? JSON.parse(savedState) : {};

         const details = document.querySelectorAll('.ha-domain-group');
         details.forEach(detail => {
             const summary = detail.querySelector('summary');
             const domainText = summary.textContent.trim();
             // Extract domain name (everything before the count span)
             const domain = domainText.split('\n')[0].trim().toLowerCase();

             // Set state from localStorage, default to open if not saved
             if (detailsState.hasOwnProperty(domain)) {
                 detail.open = detailsState[domain];
             } else {
                 detail.open = true; // Default to open
             }

             // Add event listener to save state when toggled
             detail.addEventListener('toggle', function() {
                 saveDetailsState();
             });
         });
     }

     // Save current state of all details elements
     function saveDetailsState() {
         const details = document.querySelectorAll('.ha-domain-group');
         const state = {};

         details.forEach(detail => {
             const summary = detail.querySelector('summary');
             const domainText = summary.textContent.trim();
             // Extract domain name (everything before the count span)
             const domain = domainText.split('\n')[0].trim().toLowerCase();
             state[domain] = detail.open;
         });

         localStorage.setItem('ha_details_state', JSON.stringify(state));
     }

     // Simple function to expand/collapse all domains
     function toggleAllDomains(open) {
         const details = document.querySelectorAll('.ha-domain-group');
         details.forEach(detail => {
             detail.open = open;
         });
         // Save the new state
         saveDetailsState();
     } // Function to toggle entity state
     async function toggleEntity(entityId) {
         const button = event.target;
         const originalText = button.textContent;

         try {
             // Disable button and show loading state
             button.disabled = true;
             button.textContent = 'Loading...';

             const response = await fetch('../request_handler.php?action=handleToggleAction', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/json',
                 },
                 body: JSON.stringify({
                     entity_id: entityId
                 })
             });

             const result = await response.json();

             if (result.success) {
                 // Show success feedback without reloading
                 button.textContent = 'Success!';
                 button.style.backgroundColor = '#4CAF50';

                 // Reset button after 2 seconds
                 setTimeout(() => {
                     button.textContent = originalText;
                     button.style.backgroundColor = '';
                     button.disabled = false;
                 }, 2000);
             } else {
                 alert('Error: ' + result.error);
                 button.textContent = originalText;
                 button.disabled = false;
             }
         } catch (error) {
             console.error('Error toggling entity:', error);
             alert('Error toggling entity: ' + error.message);
             button.textContent = originalText;
             button.disabled = false;
         }
     }

     // Function to control entity with specific action (turn_on, turn_off)
     async function controlEntity(entityId, action) {
         const button = event.target;
         const originalText = button.textContent;

         try {
             // Disable button and show loading state
             button.disabled = true;
             button.textContent = 'Loading...';

             const response = await fetch('../request_handler.php?action=handleDeviceAction', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/json',
                 },
                 body: JSON.stringify({
                     entity_id: entityId,
                     action: action
                 })
             });

             const result = await response.json();

             if (result.success) {
                 // Show success feedback without reloading
                 button.textContent = 'Success!';
                 button.style.backgroundColor = '#4CAF50';

                 // Reset button after 2 seconds
                 setTimeout(() => {
                     button.textContent = originalText;
                     button.style.backgroundColor = '';
                     button.disabled = false;
                 }, 2000);
             } else {
                 alert('Error: ' + result.error);
                 button.textContent = originalText;
                 button.disabled = false;
             }
         } catch (error) {
             console.error('Error controlling entity:', error);
             alert('Error controlling entity: ' + error.message);
             button.textContent = originalText;
             button.disabled = false;
         }
     }

     // Function to handle logout
     async function handleLogout() {
         const button = document.getElementById('logoutBtn');
         const originalText = button.textContent;

         try {
             // Disable button and show loading state
             button.disabled = true;
             button.textContent = 'Logging out...';

             const response = await fetch('../request_handler.php?action=handleLogout', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/json',
                 }
             });

             const result = await response.json();

             if (result.success) {
                 // Redirect to main page after successful logout
                 window.location.href = '../index.php';
             } else {
                 alert('Error logging out: ' + result.error);
                 button.textContent = originalText;
                 button.disabled = false;
             }
         } catch (error) {
             console.error('Error during logout:', error);
             alert('Error during logout: ' + error.message);
             button.textContent = originalText;
             button.disabled = false;
         }
     }
 </script>

 <div class="ha-connection">
     <?php if (isset($errors) && $errors !== ''): ?>
         <div class="ha-error"><?= htmlspecialchars($errors) ?></div>
     <?php else: ?> <p class="ha-success">Connected to Home Assistant</p>
     <?php endif; ?>
     <p class="ha-websocket-status" id="websocket-status">WebSocket: Connecting...</p>
     <button id="logoutBtn" class="ha-button" onclick="handleLogout()">Disconnect</button>
 </div>

 <div class="ha-entities-section">
     <h3>Home Assistant Entities</h3>

     <?php if ($error): ?>
         <div class="ha-error">Error fetching entities: <?= htmlspecialchars($error) ?></div>
     <?php elseif (empty($entities)): ?>
         <p>No entities found.</p>
     <?php else: ?> <div class="ha-entities-stats">
             <p>Found <?= count($entities) ?> entities</p>
         </div> <!-- Domain Control Buttons -->
         <div class="ha-domain-controls" style="margin: 15px 0;">
             <button class="ha-button" onclick="toggleAllDomains(true)">Expand All</button>
             <button class="ha-button" onclick="toggleAllDomains(false)">Collapse All</button>
             <small style="color: #666; margin-left: 15px; align-self: center;">
                 💡 Click on domain headers to expand/collapse groups
             </small>
         </div>
         <div class="ha-entities-grid">
             <?php
                // Group entities by domain
                $entitiesByDomain = [];
                foreach ($entities as $entity) {
                    $domain = explode('.', $entity['entity_id'])[0];
                    if (!isset($entitiesByDomain[$domain])) {
                        $entitiesByDomain[$domain] = [];
                    }
                    $entitiesByDomain[$domain][] = $entity;
                }                // Display each domain group
                foreach ($entitiesByDomain as $domain => $domainEntities):
                ?>
                 <details class="ha-domain-group" open>
                     <summary>
                         <?= ucfirst(htmlspecialchars($domain)) ?>
                         <span class="ha-domain-count"><?= count($domainEntities) ?></span>
                     </summary>
                     <div class="ha-domain-entities">
                         <?php foreach ($domainEntities as $entity): ?>
                             <div class="ha-entity-card" data-entity-id="<?= htmlspecialchars($entity['entity_id']) ?>">
                                 <div class="ha-entity-header">
                                     <span class="ha-entity-id"><?= htmlspecialchars($entity['entity_id']) ?></span>
                                     <span class="ha-entity-state <?= $entity['state'] === 'on' ? 'state-on' : ($entity['state'] === 'off' ? 'state-off' : 'state-other') ?>">
                                         <?= htmlspecialchars($entity['state']) ?>
                                     </span>
                                 </div>

                                 <?php if (isset($entity['attributes']['friendly_name'])): ?>
                                     <div class="ha-entity-name">
                                         <?= htmlspecialchars($entity['attributes']['friendly_name']) ?>
                                     </div>
                                 <?php endif; ?>

                                 <div class="ha-entity-details">
                                     <?php if (isset($entity['last_changed'])): ?>
                                         <small class="ha-entity-time">
                                             Changed: <?= date('M j, g:i A', strtotime($entity['last_changed'])) ?>
                                         </small>
                                     <?php endif; ?>

                                     <?php if (isset($entity['attributes']['unit_of_measurement'])): ?>
                                         <small class="ha-entity-unit">
                                             Unit: <?= htmlspecialchars($entity['attributes']['unit_of_measurement']) ?>
                                         </small>
                                     <?php endif; ?>
                                 </div>

                                 <?php
                                    // Show control buttons for different types of entities
                                    $onOffDomains = ['switch', 'light', 'automation', 'button'];
                                    $toggleDomains = ['fan', 'cover', 'lock', 'climate'];

                                    if (in_array($domain, $onOffDomains)):
                                    ?>
                                     <div class="ha-entity-controls">
                                         <button class="ha-control-btn ha-turn-on-btn" onclick="controlEntity('<?= htmlspecialchars($entity['entity_id']) ?>', 'turn_on')">
                                             Turn On
                                         </button>
                                         <button class="ha-control-btn ha-turn-off-btn" onclick="controlEntity('<?= htmlspecialchars($entity['entity_id']) ?>', 'turn_off')">
                                             Turn Off
                                         </button>
                                     </div>
                                 <?php elseif (in_array($domain, $toggleDomains) && in_array($entity['state'], ['on', 'off', 'open', 'closed', 'locked', 'unlocked'])): ?>
                                     <button class="ha-toggle-btn" onclick="toggleEntity('<?= htmlspecialchars($entity['entity_id']) ?>')">
                                         Toggle
                                     </button>
                                 <?php endif; ?>
                             </div>
                         <?php endforeach; ?>
                     </div>
                 </details>
             <?php endforeach; ?>
         </div>
     <?php endif; ?>
 </div>