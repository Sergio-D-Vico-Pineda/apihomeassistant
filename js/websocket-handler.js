class WebSocketHandler {
    constructor(haUrl, accessToken) {
        this.wsUrl = haUrl.replace('http', 'ws') + '/api/websocket';
        this.accessToken = accessToken;
        this.messageId = 1;
        this.subscriptions = new Map();
        this.stateCallbacks = new Map();
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 500;
        this.onStatusChange = (status) => {
            const wsStatus = document.getElementById('websocket-status');
            console.log('WebSocket status changed:', status);
            wsStatus.textContent = `WebSocket: ${status}`;
        };
    }

    connect() {
        try {
            this.ws = new WebSocket(this.wsUrl);
            this.onStatusChange('Connecting');

            this.ws.onopen = () => {
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
        const authMessage = {
            type: 'auth',
            access_token: this.accessToken
        };
        this.sendMessage(authMessage);
        // this.handleMessage(authMessage);
    }

    subscribeToEvents(eventType = null, callback) {
        const subscriptionId = this.messageId++;
        const message = {
            id: subscriptionId,
            type: 'subscribe_events'
        };
        if (eventType) {
            message.event_type = eventType;
        }

        this.subscriptions.set(subscriptionId, {
            eventType: eventType || 'all',
            callback
        });

        this.sendMessage(message);
        return subscriptionId;
    }

    subscribeToStates(entityIds, callback) {
        console.log('Subscribing to states:', entityIds);
        entityIds.forEach(entityId => {
            this.stateCallbacks.set(entityId, callback);
        });

        // Subscribe to state_changed events
        return this.subscribeToEvents('state_changed', (event) => {
            if (event.event_type === 'state_changed' &&
                entityIds.includes(event.data.entity_id)) {
                callback(event.data);
            }
        });
    }

    sendMessage(message) {
        console.log('Sending message:', message);
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
            console.log('Authentication successful');
            this.onAuthenticated();
            return;
        }

        // Handle authentication failure
        if (data.type === 'auth_invalid') {
            console.error('Authentication failed:', data.message);
            return;
        }        // Handle event messages
        if (data.type === 'event') {
            const subscription = this.subscriptions.get(data.id);
            console.log('Handling event:', data.event);
            console.log('Subscription:', subscription);

            // Handle the event through the callback which will use updateEntityState()
            if (subscription && subscription.callback) {
                subscription.callback(data.event);
            }
        }
    }

    handleDisconnect() {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            // const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), 30000);
            const delay = this.reconnectDelay;
            console.log(`Attempting to reconnect in ${delay}ms...`);
            setTimeout(() => {
                this.reconnectAttempts++;
                this.connect();
            }, delay);
        } else {
            console.error('Max reconnection attempts reached');
        }
    }

    onAuthenticated() {
        // Resubscribe to all previous subscriptions
        this.subscriptions.forEach((subscription, id) => {
            const message = {
                id: id,
                type: 'subscribe_events'
            };
            if (subscription.eventType !== 'all') {
                message.event_type = subscription.eventType;
            }
            this.sendMessage(message);
        });
    }

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