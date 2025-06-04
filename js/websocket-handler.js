class WebSocketHandler {
    constructor(haUrl, accessToken) {
        this.wsUrl = haUrl.replace('http', 'ws') + '/api/websocket';
        this.accessToken = accessToken;
        this.messageId = 1;
        this.subscriptions = new Map();
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 500;
        this.isAuthenticated = false;
        this.readyCallbacks = [];
        this.onStatusChange = (status) => {
            const wsStatus = document.getElementById('websocket-status');
            // console.log('WebSocket status changed:', status);
            wsStatus.textContent = `WebSocket: ${status}`;
        };
    }

    connect() {
        try {
            this.ws = new WebSocket(this.wsUrl);
            console.log('1: Connecting');
            this.onStatusChange('Connecting');

            this.ws.onopen = () => {
                console.log('2: Connected');
                setTimeout(() => {
                    this.onStatusChange('Connected');
                    this.authenticate();
                }, this.reconnectDelay);
            };

            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleMessage(data);
            };

            this.ws.onclose = () => {
                this.onStatusChange('Disconnected');
                this.handleDisconnect();
            };

            this.ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.onStatusChange('Error');
            };

        } catch (error) {
            console.error('WebSocket connection error:', error);
            this.handleDisconnect();
        }
    }

    authenticate() {
        console.log('3: Authenticating with access token');
        const authMessage = {
            type: 'auth',
            access_token: this.accessToken
        };
        this.sendMessage(authMessage);
    }

    isReady() {
        return this.ws && this.ws.readyState === WebSocket.OPEN && this.isAuthenticated;
    }

    whenReady(callback) {
        if (this.isReady()) {
            callback();
        } else {
            console.log('1.5: WebSocket not ready, adding callback to readyCallbacks');
            this.readyCallbacks.push(callback);
        }
    }

    subscribeToEvents(eventType = null, callback) {
        const subscriptionId = this.messageId++;
        console.log('Subscribing to events:', eventType, 'with ID:', subscriptionId);

        const message = {
            id: subscriptionId,
            type: 'subscribe_events'
        };

        if (eventType) {
            message.event_type = eventType;
        } this.subscriptions.set(subscriptionId, {
            eventType: eventType || 'all',
            callback
        });

        this.sendMessage(message);
        return subscriptionId;
    }

    subscribeToStates(entityIds, callback) {
        console.log('7: Subscribing to states:', entityIds);

        // Option 1: Use subscribe_trigger for specific entities (more efficient)
        // This creates one subscription per entity, which is more selective
        const subscriptionIds = [];

        entityIds.forEach(entityId => {
            const subscriptionId = this.messageId++;
            console.log(`7.1: Creating trigger subscription for entity: ${entityId} with ID: ${subscriptionId}`);

            const message = {
                id: subscriptionId,
                type: 'subscribe_trigger',
                trigger: {
                    platform: 'state',
                    entity_id: entityId
                }
            };

            this.subscriptions.set(subscriptionId, {
                entityId: entityId,
                callback: callback,
                type: 'trigger'
            });

            this.sendMessage(message);
            subscriptionIds.push(subscriptionId);
        }); return subscriptionIds;
    }

    subscribeToAllStates(entityIds, callback) {
        console.log('7alt: Subscribing to ALL states with client-side filtering:', entityIds);

        // Option 2: Subscribe to all state_changed events and filter client-side
        // Less efficient but works for any Home Assistant version
        return this.subscribeToEvents('state_changed', (event) => {
            if (event.event_type === 'state_changed'
                && entityIds.includes(event.data.entity_id)) {
                console.log('Filtered state change for:', event.data.entity_id);
                callback(event.data);
            }
        });
    }

    unsubscribe(subscriptionId) {
        console.log('Unsubscribing from subscription:', subscriptionId);

        if (Array.isArray(subscriptionId)) {
            // Handle multiple subscription IDs
            subscriptionId.forEach(id => this.unsubscribe(id));
            return;
        }

        const message = {
            id: this.messageId++,
            type: 'unsubscribe_events',
            subscription: subscriptionId
        }; this.subscriptions.delete(subscriptionId);
        this.sendMessage(message);
    }

    sendMessage(message) {
        if (message.type === 'auth') {
            console.log('4: Sending auth message:', message);
        } else if (message.type === 'subscribe_events') {
            console.log('8 <- 6.5: Sending subscribe events message:', message);
        } else if (message.type === 'subscribe_trigger') {
            console.log('8alt: Sending subscribe trigger message:', message);
        } else if (message.type === 'unsubscribe_events') {
            console.log('9: Sending unsubscribe message:', message);
        } else {
            console.log('Unknown: Sending message:', message);
        }

        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(message));
            return true;
        } else {
            console.log('WebSocket is not connected');
            return false;
        }
    }

    handleMessage(data) {
        // console.log('Received message:', data);
        // Handle authentication success
        if (data.type === 'auth_ok') {
            console.log('5: Authentication successful: Handling auth message:', data);
            // console.log('Authentication successful');
            this.isAuthenticated = true;
            // this.onAuthenticated();
            console.log('5.5: Calling readyCallbacks');
            this.readyCallbacks.forEach(callback => callback());
            this.readyCallbacks = [];
            return;
        }

        // Handle authentication failure
        if (data.type === 'auth_invalid') {
            console.error('Authentication failed:', data.message);
            return;
        }

        if (data.type === 'event') {
            const subscription = this.subscriptions.get(data.id);
            console.log('Handling event:', data.event);
            // console.log('Subscription:', subscription);

            // Aqui es donde se recibe el cambio de estado y se llama al callback
            if (subscription && subscription.callback) {
                if (subscription.type === 'trigger') {
                    // Handle trigger-based subscription (subscribe_trigger)
                    console.log('Handling trigger event for entity:', subscription.entityId);
                    const triggerData = {
                        entity_id: subscription.entityId,
                        new_state: data.event.variables.trigger.to_state,
                        old_state: data.event.variables.trigger.from_state
                    };
                    subscription.callback(triggerData);
                } else {
                    // Handle regular event subscription (subscribe_events)
                    console.log('Calling subscription callback for event:', data.event);
                    subscription.callback(data.event);
                }
            }
        }
    }

    handleDisconnect() {
        this.isAuthenticated = false;
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            console.log(`Attempting to reconnect in ${this.reconnectDelay}ms...`);
            setTimeout(() => {
                this.reconnectAttempts++;
                this.connect();
            }, this.reconnectDelay);
        } else {
            console.error('Max reconnection attempts reached');
        }
    }

    /* onAuthenticated() {
        console.log('6: Resubscribing to all previous subscriptions');
        console.log('Current subscriptions:', this.subscriptions);
        let i = 0
        // Resubscribe to all previous subscriptions
        this.subscriptions.forEach((subscription, id) => {
            console.log(`Resubscribing to subscription ${i++}.`);
            // console.log('Resubscribing to event:', subscription.eventType, 'with ID:', id);
            const message = {
                id: id,
                type: 'subscribe_events'
            };
            if (subscription.eventType !== 'all') {
                message.event_type = subscription.eventType;
            }
            this.sendMessage(message);
        });
    } */

    callService(domain, service, entityId) {
        const message = {
            id: this.messageId++,
            type: 'call_service',
            domain: domain,
            service: service,
            target: {
                entity_id: entityId
            }
        };
        return this.sendMessage(message);
    }

    handleFormSubmission(form) {
        const entityId = form.querySelector('input[name="entity_id"]')?.value;
        const domain = form.querySelector('input[name="domain"]')?.value;
        const action = form.querySelector('button[type="submit"][name="action"]:focus')?.value;

        if (entityId && domain && action) {
            return this.callService(domain, action, entityId);
        }
        return false;
    }

    close() {
        if (this.ws) {
            this.ws.close();
        }
    }
}