# Research Article 

# The Role of High Performance Computing and 

# Communication for Real-Time Biofeedback in Sport 

#### Anton Umek and Anton Kos 

 Faculty of Electrical Engineering, University of Ljubljana, Trˇzaˇska Cesta 25, 1000 Ljubljana, Slovenia 

 Correspondence should be addressed to Anton Kos; anton.kos@fe.uni-lj.si 

 Received 15 February 2016; Revised 21 April 2016; Accepted 4 May 2016 

 Academic Editor: Zoran Obradovic 

 Copyright © 2016 A. Umek and A. Kos. This is an open access article distributed under the Creative Commons Attribution License, which permits unrestricted use, distribution, and reproduction in any medium, provided the original work is properly cited. 

 This paper studies the main technological challenges of real-time biofeedback in sport. We identified communication and processing as two main possible obstacles for high performance real-time biofeedback systems. We give special attention to the role of high performance computing with some details on possible usage of DataFlow computing paradigm. Motion tracking systems, in connection with the biomechanical biofeedback, help in accelerating motor learning. Requirements about various parameters important in real-time biofeedback applications are discussed. Inertial sensor tracking system accuracy is tested in comparison with a high performance optical tracking system. Special focus is given on feedback loop delays. Real-time sensor signal acquisitions and real-time processing challenges, in connection with biomechanical biofeedback, are presented. Despite the fact that local processing requires less energy consumption than remote processing, many other limitations, most often the insufficient local processing power, can lead to distributed system as the only possible option. A multiuser signal processing in football match is recognised as an example for high performance application that needs high-speed communication and high performance remote computing. DataFlow computing is found as a good choice for real-time biofeedback systems with large data streams. 

#### 1. Introduction 

Science, engineering, and cutting edge technology are being increasingly valued in modern sports. They offer new knowledge, expertise, and tools for achieving a competitive advantage. One such example is the use of biomechanical biofeedback systems. In this paper, the word biofeedback denotes a body activity in the sense of physical movement and it is classified as biomechanical biofeedback [1]. 

In a biofeedback system a user has attached sensors for measuring body functions and parameters (bio). Sensor signals are transferred to a signal processing device and results are communicated back to the person (feedback) through one of the human senses (i.e., sight, hearing, and touch) [2]. The person tries to act on the received information to change the body motion in the desired way. 

One of the most common uses of biomechanical biofeedback is motor learning in sports, recreation, and rehabilitation [3, 4]. The process of learning new movements is based on repetition [1]. Numerous correct executions are required to adequately learn a certain movement. Biofeedback is 

 successful, if the user is able to either correct a movement or abandon its execution given the appropriate biofeedback information. The primary focus of this paper is the study of real-time biomechanical biofeedback systems that make the concurrent biofeedback feasible. The concurrent biofeedback can reduce the frequency of improper movement executions and speed up the proper movement pattern learning process. Such learning methods are suitable for recreational, professional, and amateur users in the initial stages of the movement learning process [5]. Initially, this process requires additional learning cycles until the user understands the feedback information. The general architecture of the biomechanical biofeedback system is illustrated in Figure 1. It includes sensor(s), a processing device, a biofeedback device, and communication channels [6]. Together with the user they form a biofeedback loop. Sensors are the essential components of the system. The system should be designed to work with one or multiple sensor devices. Sensors represent the capture side of 

Hindawi Publishing Corporation Mathematical Problems in Engineering Volume 2016, Article ID 4829452, 11 pages [http://dx.doi.org/10.1155/2016/4829452](http://dx.doi.org/10.1155/2016/4829452) 


 Processing device 

 Biofeedback device 

 User (re)action 

 Biofeedback signals Sensor(s) 

 .. . 

Figure 1: Architecture and operation of a biomechanical biofeedback system. Multiple sensors feed their signals to the processing device for real-time signal analysis. Analysis results (biofeedback signals) drive the biofeedback device activity. User’s (re)action alters sensor signals, thus closing the biofeedback loop. 

the system and are usually attached to the user’s body. They are the source of signals and data used by the processing device. Motion capture systems employ various sensor technologies for motion acquisition. The most common are camera based systems and inertial sensor based systems [5]. Camera based systems can be further divided into two main subcategories: (a) video based systems and (b) marker based systems. The former directly process the video stream captured at various light wavelengths; the latter use passive or active markers for determining their position in space and time. It should be emphasized that inertial sensor based motion tracking systems are generally mobile and have no limitation in covering space. Modern inertial sensors are miniature low power chips integrated into wearable sensor devices. The processing device receives sensor signals, analyzes them, and, when necessary, generates and sends feedback signals to the biofeedback devices. A processing device is any device capable of performing computation on sensor signals. The computation can be performed in two basic modes: (a) during the movement: this mode requires processing in real time; such operation is denoted as concurrent biofeedback; or (b) after the movement: this mode allows postprocessing; such operation is denoted as terminal biofeedback. The processing device can be located locally, on the user, performing local processing or remotely, away from the user, performing remote processing. The biofeedback device employs human senses to communicate feedback information to the user. The most commonly used senses are hearing, sight, and touch. For the feedback it is desirable to use the modality with the least cognitive load induced by other activities. Communication channels enable the communication between the independent biofeedback system devices. Although wireless communication technologies are most commonly used, wired technologies can also be used in practice. With local processing both technologies can be used, and with remote processing only wireless technologies are practical. The operation of biomechanical biofeedback systems largely depends on parameters of human motion and analysis 

 algorithms. Biomechanical biofeedback is based on sensing body rotation angles, posture orientation, body translation, and body speed. These parameters are generally calculated from raw data that represent measured physical quantities. Important parameters of human motion should therefore be adequately acquired by the chosen capture system (sensors). Sensors of the motion capture system should have (a) sufficiently large dynamic ranges for the measured motion quantity, (b) sufficiently high sampling frequency that covers all frequencies contained in the motion, and (c) sufficiently high accuracy and/or precision. The employed processing devices should have sufficient computational power for the chosen analysis algorithms. While this is generally not critical with terminal biofeedback that uses postprocessing, it is of the outmost importance with concurrent biofeedback that requires real-time processing. In biofeedback systems with real-time processing all computational operations must be completed within one sampling period. When sampling frequencies are high, this demand can be quite restricting, especially for local processing devices attached to the user. 

 Related Work. Biomechanical biofeedback in sport is particularly useful in motor learning [2]. Recent advances in technology allow development of realistic and complex unimodal or multimodal biofeedback systems with concurrent augmented feedback [2]. Ubiquitous computing, with its synergetic use of sensing, communication, and computing, is quickly entering sport applications [3]. Sports applications are becoming increasingly mobile; the abundance of relatively inexpensive sensors is producing large amounts of data. Consequently information, communication, and computing technologies are becoming increasingly important in sport [3, 5]. Wireless transmission and storage components are the most power demanding components in sensor signal processing. Consequently, local signal processing is more power efficient than remote signal processing [7]. However, power consumption is not necessarily the only crucial parameter, especially for applications where high processing demands exceed the sensor node local processing power. The authors in [8] introduce the concept of wireless body area networks (WBAN) for big data medical applications where a constant data flow from various sensors is collected in long periods of time for a very large set of sensor nodes. The total amount of data requires a big data processing framework. One of the goals of sport application development is a wearable real-time biofeedback system. Wearable sensor devices can improve training due to high mobility, ubiquity, and intelligent feedback offered. Authors in [9] present a wearable platform that provides baseball players with corrective feedback. A comprehensive review of wearable sensing in human body biomechanics, with focus on sensing and analytics, can be found in [10]. Authors present different sensors and list several studies in the fields of pathology, rehabilitation, sport, and others. In the recent years DataFlow computing paradigm has been rediscovered [11] and successfully applied to many areas of high performance computing (HPC) [12, 13]. It has been 


shown in many examples that for specific problems DataFlow computing outperforms ControlFlow computing [14]. Such examples include streamed data that have to be processed in real time. 

#### 2. Challenges in Real-Time Biofeedback 

Concurrent (real-time) biofeedback can only be incorporated successfully when (a) human reactions are performed in movement, that is, inside the time frame of the executed movement pattern, and (b) the biofeedback system operates in real-time with minimal delay. An ideal real-time biomechanical biofeedback system is an autonomous, wearable, lightweight system with large enough number of sensors that are able to capture all the important motion parameters. Sensor signals must exhibit high enough sampling frequency and accuracy. Processing is done instantly and the feedback modality must be chosen in a way that it is not interfering with the principal modality of the motion. Real systems tend to get as close to the ideal system as possible. The main challenges in this effort are often contradictory. For example, under the constraints of technology, the ideals of being wearable and lightweight contradict the ideals of autonomy and processing power because of the battery time. The first challenge is to achieve the desired accuracy and precision of motion capture. Inaccuracies and errors present in various capture systems limit the usability in certain cases. For example, the use of MEMS accelerometers for position tracking is useless because even a small inaccuracy in sensor readings induces a positional error that is quadratically proportional to the tracking time. Another challenge is the sampling frequency. Achieving high enough sampling frequency is generally not a problem, but it leads to large amounts of sensor data that needs to be transferred to the processing device and analyzed. Problems that may occur are available bandwidth of the communication channels and the computational power of the processing device. The latter is especially a problem in realtime biofeedback systems. Here it should be noted that higher sampling frequency 𝑓𝑠 yields shorter sampling time 𝑇𝑠, thus allowing less time to complete the computation cycle needed for each sensor signal sample. Communication channel bandwidth, range, and delays are yet another set of potential problems. Low power wearable devices usually have low channel bandwidth and very limited communication range. In packet based technologies the delay is linearly proportional to packet length and inversely proportional to bandwidth. Longer packets and/or lower bandwidth cause higher delays. To increase the communication protocol efficiency more than one signal sample can be included into one data packet. Each additional sample increases the communication delay for one sampling time 𝑇𝑠. 

#### 3. Biomechanical Biofeedback Systems 

Biomechanical biofeedback systems can be divided into two basic groups on the grounds of processing device location. 

 Processing device 

 Sensor 

 Actuator 

 Figure 2: A personal biofeedback system. All system devices are attached to the user. Wearable processing device tends to be the most critical element of the system in terms of its computational power and/or battery time. 

 Processing device 

 Sensor 

 Actuator 

 Figure 3: Distributed biofeedback system. Sensor(s) and actuator(s) are attached to the user. The processing device is at the remote location, away from the user. Communication channels tend to be the most critical element of the system in terms of range, bandwidth, and delays or any combination of the mentioned. 

 We denote a system with local processing as a personal biofeedback system and a system with remote processing as a distributed biofeedback system. The former is presented in Figure 2 and the latter in Figure 3. A personal biofeedback system is compact in the sense that all system devices are attached to the user and are in close vicinity of each other; see Figure 2. Because the distances between devices are short, the communication can be performed through low-latency wireless channels or over wired connections. The primary concern of personal biofeedback systems is the available computational power 


of the processing device. The personal version is completely autonomous. The user is free to use the system at any time and at any place and is not limited to confined spaces. In distributed biofeedback system sensor(s) and actuator(s) are attached to the user’s body, while the processing device is at a remote location; see Figure 3. Based on the distance between the user and the processing device, distributed systems are further divided into two subgroups: (a) local and (b) network. In the local subgroup the processing device is located close to the user, for example, just outside the playing field, next to the running track, and at the base of the skiing slope. In the network subgroup the processing device is located somewhere in the network, for example, on a PC in the laboratory or on the server in the cloud. The primary concern of distributed biofeedback systems is communication channel ranges, bandwidths, and increased feedback loop delays. Distributed versions, especially the network version, have high computational power. With the local version of the system the user might be limited to a confined space if the communication channel technology has short coverage range. 

3.1. Trade-Off between Local and Remote Processing. In wearable systems the battery time is often the most limiting factor; hence energy consumption in the biofeedback loop is of the prime concern. When having the possibility of using either a personal or a distributed version of the biofeedback system, one should consider choosing the system with the optimum energy consumption for the given task. According to [7] sensor devices consume many times more energy for radio transmission and memory storage than for local processing. This means that personal version with local processing could be more favorable option than distributed version with remote processing. In this context of the local processing it is implied that the signal is first (partially) processed inside the sensor device. The results are then communicated to the processing device of the biofeedback system for possible further processing. Energy-wise local processing at sensor device is very attractive, but there are some limitations that should be considered [7]: 

 (a) Algorithms developed in widespread software environments such as MATLAB are difficult to port to sensor devices. (b) Sensor devices use microcontrollers for signal processing. They do not have a floating point unit and floating point operations must be simulated by using fixed point operation. This is slow and induces calculation errors. 

 (c) Total energy needed for all operations of one cycle could be higher than the energy needed for radio transmission of the raw data of the same cycle. 

 (d) Data from more than one sensor must be processed by a single algorithm instance. 

 (e) Computational load of the algorithm could be too high to be handled by the sensor device; that is, the 

 time needed to finish all operations of one cycle is longer than sampling time. (f) More cooperative users are active in a biofeedback system at the same time. 

 When one or more of the abovementioned limitations apply, distributed system with remote processing is a better option. The advantages of the distributed biofeedback system are as follows: 

 (a) The processing device has practically unlimited energy supply, high processing power, and large amounts of memory storage. (b) The processing is flexible in terms of software environments usage, algorithm changes, algorithm complexity, choice of technology, choice of computing paradigm, and so forth. (c) It has a central point of sensor data synchronization when more users are simultaneously using a biofeedback system. (d) High performance computing solutions can be used when the amounts of data and/or computational complexity increases. 

 The choice of the most appropriate biofeedback system depends on each separate application. In this paper we focus on the distributed version supported by HPC systems. 

 3.2. Delays and Processing Times in the Biofeedback Loop. Biofeedback loop has two basic modes of operation: (a) concurrent biofeedback where user is receiving the feedback during the movement; this mode requires processing in real time; (b) terminal biofeedback where user is receiving the feedback after the movement; this mode allows postprocessing. The paper focuses on concurrent feedback with real-time processing. There are two basic points of view on delays in concurrent (real-time) biofeedback systems: (a) user’s point of view and (b) system’s point of view. The delays in the real-time biofeedback system are illustrated in Figure 4. From the user’s perspective the feedback delay occurs during the user action (movement execution). In Figure 4 this delay is depicted as biofeedback delay. This is the delay that occurs between the start of the user’s action and the time when the user reacts to the feedback signal. The biofeedback delay should be as low as possible. It is heavily dependent on user’s reaction delay, which is not under the control of the biofeedback system devices. Biofeedback signals are artificial signals that augment natural human intrinsic feedback [1]. To our knowledge, there has not been any research that studies the time parameters of concurrent biofeedback in connection with human reaction times. For the purpose of this paper we define the maximal feedback delay to be a portion of the reaction delay, for example, one-tenth or one-fifth of the reaction delay; see Figure 4. More interesting is the feedback loop delay that consists of system’s devices processing delays and communication delays between them. The processing delays of sensors and 


 Processing device 

 Biofeedback delay 

 Feedback loop Reactiondelay delay Processingdelay 

 Communication delay 1 

 Communication delay 2 

 Sensor(s) 

 Actuator 

Figure 4: Real-time biofeedback system operation and delays. User movement (action) is captured by sensor(s) and their signals are sent to the processing device for analysis. Analysis results are sent to actuators, which use one of the human modalities to communicate the feedback to the user, who tries to react on it. Users perceive only the entire biofeedback delay (blue dotted line) defined as a sum of all delays in the system. The biofeedback system devices can control only the feedback loop delay, defined as a sum of all communication and processing delays of sensor(s), processing device(s), actuator(s), and communication paths. 

actuators are considered to be negligibly low comparing to communication delays and processing delay of the processing unit; therefore we consider them to be zero. The feedback loop delay should be a small portion of the human reaction delay, which depends on the modality (visual, auditory, and haptic) used for the feedback. Human reaction time is studied in numerous works. Results of reaction time measurements in [15–19] show that auditory reaction time (ART) is lower than visual reaction time (VRT). This is true for professional athletes, recreational sportsmen, and sedentary subjects. The shortest reaction times are measured in sprint starts [19]. They can be below 100 ms, but sprint start is a very special case. Generally, trained athletes have ART between 150 ms and 180 ms and VRT between 190 ms and 220 ms. Authors of [18] have measured that the ART of sedentary and regularly exercising medical students is 229 ms and 219 ms, respectively. Similarly, the VRT of both groups is 250 ms and 235 ms, respectively. To cover the great majority of sports we should presume that the reaction time of a trained athlete is around 150 ms. Communication and processing delays within the feedback loop depend heavily on the parameters of the devices and technologies used. Some of the most important parameters are sensor sampling frequency, processing unit computational power, communication channel throughput, and communication protocol delay. In general the feedback loop delay 𝑡𝐹 is the sum of communication delay between the sensor(s) and the processing device 𝑡𝑐1, processing delay 𝑡𝑝, communication delay between the processing device and actuator(s) 𝑡𝑐2, sensor sampling time 𝑡𝑠, and actuator sampling time 𝑡𝑎: 

𝑡𝐹 = 𝑡𝑠 + 𝑡𝑐1 + 𝑡𝑝 + 𝑡𝑐2 + 𝑡𝑎. (^) (1) In general the biofeedback system operates in the cycle that is equal to one of the systems' device sampling time. Sensor sampling time is the most obvious choice. It should be emphasized that in the real-time operation of the system it is required that the processing time does not exceed the sensor sampling time 𝑡𝑝 ≤ 𝑡𝑠. 

#### 4. Capturing of Human Motion 

 Motion capture systems (MCS) are an important area of research connected to biofeedback systems. The majority of MCS are based upon various optical systems and inertial sensors. Motion is captured through measurement of various physical quantities such as acceleration, velocity, position, angular velocity, rotation angle, power, and energy. Optical systems generally give spatial positions of markers; inertial sensors generally give acceleration (accelerometer) and angular speed (gyroscope). The rest of the physical quantities that are needed by the system are calculated from the measured sensor quantities. Experimentally we have evaluated two different motion capture systems: (a) MEMS gyroscope based system and (b) passive marker based optical system. We have primarily focused on parameters required by the real-time biofeedback (loop delay and processing cycle) and on the accuracy of body rotations derived from the optical system spatial position measurements and MEMS gyroscope angular velocity measurements. 

 4.1. Optical Motion Capture System. We used a professional optical motion capture system Qualisys. This is a highaccuracy tracking system [20] with eight Oqus 3+ high-speed cameras that offers real-time tracking of multiple marker points as well as tracking of predefined rigid bodies. Sampling frequency of the system is up to 1000 Hz. We can obtain the 3D position of each marker every millisecond. Infrared reflecting markers are attached to the rigid acryl frame, which is at the same time the encasement for the gyroscope. The markers are attached to the frame in a way to form the orthogonal vector basis of the rigid body in gyroscope’s 𝑥-𝑦 plane. 

 4.2. Inertial Sensors Capture System. We used the MEMS gyroscope L3G4200D, manufactured by STMicroelectronics [21]. Gyroscope device is fixed into the rigid acryl frame with the attached reflective markers of the optical system. 

 4.3. Accuracy. As stated in [22] the measurement noise for a static marker is given by its standard deviation for each individual coordinate: std𝑥 = 0.018 mm, std𝑦 = 0.016 mm, and std𝑧 = 0.029 mm. In view of the given results, we can regard the measurement inaccuracy of the optical tracking system as negligibly small. Inertial sensor accuracy is limited by the precision of self-adhesive reflective marker positioning. The measured positional accuracy of the rigid acryl frame is better than 0.25 mm. Considering the rigid body dimensions and marker distances, this yields the predicted rotation error under 0.5 degrees. The achieved 


 5 10 15 20 

 −100 

 −50 

 0 

 50 

 100 

Figure 5: Comparison of gyroscope (doted black) and Qualisys body rotation angles (solid color) in the global sensor-body coordinate system. Red = roll, green = pitch, and blue = yaw. The horizontal and vertical axes represent time [s] and angle [deg], respectively. 

gyroscope rotation measurement accuracy is better than 1 degree. Figure 5 shows the comparison of the body Euler rotation angles measured by both tracking systems. A testing rotation pattern was generated by a smooth hand-driven object rotation sequence in a ten-second time interval. The RMSE in the time interval between 8 s and 18 s for the measured rotation angles for the 𝑥-axis, 𝑦-axis, and 𝑧-axis of the gyroscope are 1.11 deg, 0.81 deg, and 0.99 deg, respectively. Such accuracies are good enough for most biofeedback systems [6]. 

4.4. Sampling Frequency. Due to real-time communication speed limitations of Qualisys and inertial sensor device, the above experiments are performed at sampling frequencies of 60 Hz [20, 23]. While such sampling frequency is sufficient for evaluation of motion capture system accuracies, it is too low for capturing movements in sport. With sampling frequency of 60 Hz only movements with maximal frequency component of 30 Hz or less can be captured correctly. To estimate the required sampling frequencies for capturing human motion in sport, we performed a series of measurements with wearable Shimmer3 inertial sensor device. Shimmer3 allows accelerometer and gyroscope sampling frequencies of up to 2048 Hz. The maximal dynamic ranges of Shimmer3 accelerometer and gyroscope are ±16 g and ±2000 deg/s, respectively. A set of time and frequency domain signals for a handball free-throw movement is shown in Figure 6. The sensor device was attached at the dorsal side of the hand. Measured acceleration and rotation speed values shown in Figures 6(a) and 6(d) are close to the limit of the sensors dynamic range. High sampling rate enables the measurements of actual spectrum bandwidth for both physical quantities. Most of the energy of finite time signals is within the upper limited frequency range, as shown in Figures 6(b) and 6(e). The bandwidth containing 99% of signal energy 𝐸(99%) is a useful measure of signal bandwidth as shown in Figures 6(c) and 6(f). The signal spectrum bandwidths differ in each dimension and are higher than for absolute 3D values. The 

 highest measured values in Figures 6(c) and 6(f) are 59 Hz for acceleration and 40 Hz for rotation speed. For some other, more dynamic, explosive movements we have measured the frequencies 𝐸(99%) that exceed 200 Hz, requiring sampling frequency of 500 Hz. All the experiments were performed by the amateurs and it is expected that professional athlete’s movements are even more dynamic, requiring higher sampling frequencies, for example, 1000 Hz. For the purpose of further discussion in this paper we assume that the maximum required sampling frequency of real-time biofeedback systems in sport is 1000 Hz. 

#### 5. Transmission of Captured Motion Data 

 Motion capture systems can produce large quantities of sensor data that are transmitted through communication channels of a biofeedback system. When real-time transmission is required, the capture system forms a stream of data with data frames that are transmitted at every sampling episode, that is, with the frequency equal to the sampling frequency of sensors. In Section 3 we distinguish two basic variants of biofeedback systems: personal and distributed. In real-time biofeedback systems two main transmission parameters are important: bit rate and delay. While bit rate depends on the used technology, delay 𝑡delay depends on signal propagation time 𝑡prop, frame transmission time 𝑡tran, and link layer protocol 𝑡MAC or medium access control protocol (MAC): 

𝑡delay = 𝑡prop + 𝑡tran + 𝑡MAC. (^) (2) At the constant channel bit rate 𝑅, the transmission delay 𝑡tran is linearly dependent on the frame length 𝐿 𝑡tran = 

###### 𝐿 

###### 𝑅 

###### . (3) 

 Propagation time on different transmission media is 3.5 to 5 nanoseconds per meter. It is sufficiently small to be neglected. MAC delays vary considerably with channel load, from a few tens of microseconds to seconds. In lightly to moderately loaded channel MAC delays are below 1 ms. In most cases that leaves the transmission delay as the main delay factor in biofeedback systems. Personal biofeedback systems can use body sensor network (BSN) technologies that have bit rates from a few tens of kilobits per second up to 10 Mbit/s [7]. Considering the projected sampling frequency of 1000 Hz, that yields the maximal possible frame size in the range of a few tens of bits (a few bytes) for low-speed technologies and up to 10,000 bits (1250 bytes) for the high-speed technologies. The range of BSN is typically a few meters. Distributed biofeedback systems use various wireless technologies with bit rates from a few hundreds of kbit/s up to few hundreds of Mbit/s [24]. Considering the projected sampling frequency of 1000 Hz, that yields the maximal possible frame size in the range of a few hundreds of bits up to 100,000 bits. The range of considered wireless technologies is between 100 m (WLAN technologies) and a few kilometres (3G/4G mobile technologies). 


 0 0.5 1 1.5 2 t (s) 

 −250 

 −200 

 −150 

 −100 

 −50 

 0 

 50 

 100 

 150 

 200 

 Acceleration (m/s 

 2 ) 

 (a) 

 −2000 

 −1500 

 −1000 

 −500 

 0 

 500 

 1000 

 Angular speed (deg/s) 

 0 0.5 1 1.5 2 t (s) 

 (b) 

 0 20 40 60 f (Hz) 

 0 

 10 

 20 

 30 

 40 

 Ampl. spect. (m/s 

 2 /Hz) 

 (c) 

 0 

 50 

 100 

 150 

 200 

 250 

 Ampl. spect. (deg/s/Hz) 

 0 20 40 60 f (Hz) (d) 

 E ( 99 %) 

 0 

 20 

 40 

 60 

 80 

 100 

 (%) 

 0 20 40 60 f (Hz) (e) 

 E ( 99 %) 

 0 

 20 

 40 

 60 

 80 

 100 

 (%) 

 0 20 40 60 f (Hz) (f) 

Figure 6: An example of a high dynamic movement: a handball free-throw hand movement measured by a 6-DoF sensing device: (a) accelerometer and (b) gyroscope signals are sampled with 1024 Hz. Signal spectrum (DFT) is calculated on the sequence of 2048 data points inside the 2 s time frame for (c) accelerometer and (d) gyroscope. Signal bandwidth is measured and calculated by the relative cumulative energy criterion 𝐸(99%) for (e) accelerometer and (f) gyroscope. 

Communication is a problem in real-time biofeedback applications and in high-speed sensing in general. For example, sensor signals shown in Figure 6 are acquired by logging and postprocessing and not by streaming and processing in real-time. Although Shimmer3 sensor device does support streaming, the bit rate of the Bluetooth technology used for the transmission sensor data is not high enough for streaming 9-DoF sensor signal data with sampling frequency of 1024 Hz. 

#### 6. Real-Time Processing of Human Motion 

In real-time biofeedback systems the processing device is receiving a stream of data frames with interarrival times that are averagely apart for system’s sampling time 𝑇𝑠. To assure real-time operation of the system, all operations on received 

 data frame must be done within one sampling time, before the arrival of the next frame. The threshold of real-time operation of the processing device depends on many factors: computational power of the processing device, sampling time, amount of data in one streamed frame, number of algorithms to be performed on the data frame, complexity of algorithms, and so forth. It is therefore difficult to set exact thresholds or values for each parameter of the processing device. Processing is a real problem in real-time biofeedback systems. For example, in Section 4 we present a comparison of optical and inertial sensor based capture systems that are operation in real time. In essence this comparison mimics the operation of a real-time biofeedback system to the point of the processing device. Despite the fact that Qualisys has 


video frame rates of up to 1000 Hz, the comparison could be done only up to sampling frequencies of 60 Hz. We identified the reason for this limitation in the processing load for the real-time calculation of the 6-DoF orientation that could not be met by laptop processing power. It should be mentioned here that Qualisys by itself already is HPC system. It has 8 cameras with integrated Linux system doing parallel processing of captured video. The results of marker positions are communicated to the central processing device (laptop) for synchronization and further processing. 

#### 7. The Need for HPC in 

#### Real-Time Biofeedback 

In Section 3 we have studied the trade-offs between the local and remote processing of biofeedback signals. While many examples of biofeedback applications exist that do not require huge amounts of processing, one can easily find examples that require HPC. One such example is a high performance real-time biofeedback system for a football match. Parameters at the capture side of the system are 22 active players, 3 judges, 10 to 20 inertial sensors per person, 1000 Hz sampling rate, and up to 13-DoF data. The data includes 3D accelerometer readings, 3D gyroscope readings, 3D magnetometer readings, GPS coordinates, and the time stamp. The first three sensors most often produce 16-bit values for each of the three axes, time stamp is 32 or 64 bits long, and GPS coordinates are 64 bits each. We must consider that GPS readings can be obtained only approximately 20 times per second. Taking the lower values of parameters (10 sensors, 32 bits for time stamp) the data rate produced is 44 Mbit/s. Taking the higher values of parameters (20 sensors, 64 bits for time stamp) the data rate produced is 104 Mbit/s. Both data rate values are calculated under the assumption that all sensor data is sent in binary format. Adding the protocol overhead, that is, for example, 30 bytes for IEEE 802.11 technologies, transmission rates on the communication channel are 104 Mbit/s and 224 Mbit/s, respectively. Such data rates can be handled only by the most recent IEEE 802.11 technologies that promise bit rates in Gbit/s range. The presented example clearly implies some form of highspeed communication and some form of HPC, especially when complex algorithms and processes are used on them. Algorithms that are regularly performed on streamed sensor signals in biofeedback systems are [25–31] statistical analysis, temporal signal parameters extraction, correlation, convolution, spectrum analysis, orientation calculation, matrix multiplication, and so forth. Processes include motion tracking, time-frequency analysis, identification, classification, and clustering. Algorithms and processes can be applied in parallel or consecutively, depending on the algorithm flow. 

7.1. The Role of DataFlow Computing. In recent years growth rate of data volumes is overpassing the growth rate of available processing power. New data-collecting technologies, among which are various sensing technologies, sensor networks, and Internet of things, are contributing to the data growth. How 

 can we process such amounts of data? DataFlow computing, a new computing paradigm, may offer solutions to many of the arisen problems. It is argued in [32] that the shift from process oriented computing (ControlFlow) to data oriented computing (DataFlow) should be done. This can be achieved by employing DataFlow computing paradigm, DataFlow programming model, and DataFlow computers [14]. The advantage of DataFlow computers, compared to ControlFlow computers, is the acceleration of the data flows and execution loops for one or more orders of magnitude. Acceleration order depends on the reusability of data within the computational process. This quality is available because of compiling the algorithms and processes below the machine code, down to the gate level [14]. This yields beneficial effects: lower execution time, less energy needed, and smaller equipment size. Many applications have been successfully transferred to the DataFlow computers [11, 12]. Strong focus of DataFlow computing is on data streams. Predefined data paths are used for streaming data from their source to their destination. The above process represents a directed graph where data flows between the nodes where computing operations are performed. This nature of DataFlow computing allows that large data streams are processed in real time. This is extremely beneficial for processing of data streams generated in sensor networks, or as in our case, large number of sensor devices in a real-time biofeedback system. 

 7.2. DataFlow Computing in Real-Time Biofeedback Systems. Is the DataFlow computing the HPC of choice for realtime biofeedback systems with large data streams? If we assume that transferring large amounts of sensor data to the processing device is not a problem, then DataFlow computing is very suitable for the task. It can even be said that DataFlow computing is ideal for real-time sensor data stream processing. We can illustrate this claim on an example. Algorithms in DataFlow computers are programmed in a form of a directed graph where data flows from the inputs of the graph to its outputs. Predefined computational operations on flowing data are performed in graph nodes. In every computational cycle one set of data can be sent to the inputs. This set of data then flows through the directed graph and is being transformed according to the predefined operations in each graph node. After a certain number of cycles that represent the latency or the depth of the DataFlow algorithm, the results turn up at the outputs. After the first result with the latency corresponding to graph depth, the next results turn up at every computational cycle. When implementing real-time biofeedback systems on a DataFlow processing device, all the sensor data can be simultaneously sent to the inputs of the DataFlow algorithms at each sampling period. After the initial latency that depends on the implemented algorithm, results simultaneously show up on the outputs at each sampling period. This property of DataFlow computers assures processing in real time. The latency of results is not a problem when it does not represent a big portion of human reaction time. Knowing that DataFlow computers operate at least at 


 frequencies of 200 MHz, even large number of cycles required for the algorithm should not be a problem. For example, if we define that DataFlow algorithm latency should be less than 5 ms, then the depth of the algorithm should not exceed 25,000. In the recent years a large number of algorithms have been adapted to DataFlow computers. Many of those algorithms that are applicable to streamed sensor data processing in realtime biofeedback systems can be found in [13]. Based on all of the above, we argue that the application of DataFlow computers is extremely beneficial for real-time biofeedback applications with the need for HPC. 

 7.3. Feasibility of High Performance Real-Time Biofeedback. As viewed from the user’s perspective, the feedback delay is the primary parameter defining the concurrency of a biofeedback system. In Section 3 we define that the feedback delay, that is, the sum of all delays of the technical part of the biofeedback system (sensors, processing device, actuator, and communication channels), should not exceed a small portion of the user’s reaction delay. To present an exemplary calculation, let us set the sampling frequency at 1000 Hz and maximal feedback delay at 20% of user’s reaction delay. Considering that the reaction time of trained athletes is defined at 150 ms (see Section 3), the maximal feedback delay must be less than or equal to 30 ms. Samples of captured motion are occurring every millisecond; accordingly the processing device must calculate one result every millisecond. When using the ControlFlow computing the processing device receives a new frame of sensor data every millisecond and it has 1 ms to perform all the calculations, leaving 29 ms for the communication path delays. When using the DataFlow computing the processing device receives a new frame of sensor data every millisecond; then data flows through the algorithm graph that introduces latency according to the graph depth. Results turn up at the output of the processing device every millisecond. When the latency of the algorithm is 10 ms, then 20 ms is left for the communication path delays. Implementation of high performance real-time biofeedback systems is feasible. The choice of the most appropriate HPC system, ControlFlow or DataFlow, depends on operating conditions of the system, primarily on the available communication path delays. 

7.4. Examples of HPC Algorithms in Real-Time Biofeedback. To illustrate the need of HPC and benefits of DataFlow computing in real-time biofeedback systems, we present two examples of computationally demanding algorithms that are used in such systems. To enable the comparison of ControlFlow and DataFlow HPC computing, we chose two computationally intensive algorithms that have already been implemented in both. The algorithms in question are CooleyTuckey FFT algorithm and dense matrix multiplication. 

 7.4.1. Cooley-Tuckey Fast Fourier Transform. Discrete Fourier Transform (DFT) is probably the most popular digital signal 

 x[0] 

 x[1] 

 x[2] 

 x[3] 

 x[4] 

 x[5] 

 x[6] 

 x[7] 

 −1 

 −1 

 −1 

 −1 −1 

 −1 

 −1 

 −1 

 −1 

 −1 

 −1 

 −1 

 W 80 

 W 80 

 W 80 

 W 81 

 W 82 

 W 82 

 W 82 

 W 83 

 X[7] 

 X[6] 

 X[5] 

 X[4] 

 X[3] 

 X[2] 

 X[1] 

 X[0] 

 W 80 

 W 80 

 W 80 

 W 80 

 Figure 7: Radix-2 FFT algorithm graph. 

 processing time-frequency transformation in engineering: 

###### 𝑋 [𝑘] = 

 𝑁−1 ∑ 𝑛=0 

###### 𝑥 [𝑛] ⋅ 𝑊𝑁𝑛⋅𝑘 , (4) 

###### 𝑊𝑁 = 𝑒−𝑗(2𝜋/𝑁). (5) 

 The computational complexity of (4) is on the order of 𝑂(𝑁^2 ). Most often the DFT is calculated for real data samples, where only (𝑁/2 − 1) complex transform values and two real values 𝑋[0] and 𝑋[𝑁/2] are needed. One of the main reasons for DFT popularity for large signal vectors is the availability of the optimized “fast” DFT algorithm or Fast Fourier Transform (FFT) for its numerical calculation. An optimized FFT algorithm [33] exploits several properties of the root-of-unity complex multiplicative constant 𝑊𝑁 (5), also known as “twiddle factor”: symmetry, periodicity, and recursion property. The computational complexity of FFT is on the order of 𝑂(𝑁 log 𝑁). The reduction of number of multiplications is done by combining recursion and “divide and conquer” approach, where on each stage, by splitting the sequence, only onehalf of multiplications are needed. FFT algorithm is often illustrated with signal graphs, where each node represents addition and each arrow represents a complex multiplication. Among several known methods, Figure 7 illustrates the 8point decimation in time FFT algorithm. Input data samples are sorted in the reverse-bit order. The calculation of FFT is already optimized for the number of operations. The structure from Figure 7 represents a directed graph which is perfectly aligned with the DataFlow computing concept. The authors in [34] have implemented the Cooley-Tuckey FFT algorithm on the Maxeler DataFlow machine MAX3242A. They report the speedup over the CPU Intel Xeon with the frequency of 3.6 GHz from approximately 23 times for 8-point FFT up to approximately 32 times for 64point FFT. 


 × = 

 (a) 

A (^22) A (^12) A (^21) A (^11) B 22 B 12 B 21 B 11 C 22 C 12 C 21 C 11 × = (b) Figure 8: Dense matrix multiplication. (a) Na¨ıve implementation and (b) tiled implementation allowing data reuse in parallel algorithms. 7.4.2. Dense Matrix Multiplication. Matrix multiplication is an important operation in many numerical algorithms used in various fields of scientific and engineering computing. Many algorithms for matrix multiplication have been developed for different types of computational systems, from hardware implementation to parallel and distributed systems. A matrix is considered dense when most of its elements are nonzero. A direct (na¨ıve) application of mathematical definition involves a dot product between every row of a matrix against every column of another matrix; see Figure 8(a). Thus, the multiplication of two 𝑛 × 𝑛 matrices requires the time on the order of 𝑂(𝑛^3 ): 

###### 𝐶 = ( 

###### 𝐶 11 𝐶 12 

###### 𝐶 21 𝐶 22 

###### ) = ( 

###### 𝐴 11 𝐴 12 

###### 𝐴 21 𝐴 22 

###### ) ( 

###### 𝐵 11 𝐵 12 

###### 𝐵 21 𝐵 22 

###### ) 

###### = ( 

###### 𝐴 11 𝐵 11 + 𝐴 12 𝐵 21 𝐴 11 𝐵 12 + 𝐴 12 𝐵 22 

###### 𝐴 21 𝐵 11 + 𝐴 22 𝐵 21 𝐴 21 𝐵 12 + 𝐴 22 𝐵 22 

###### ). 

###### (6) 

Many algorithms for efficient matrix that lower the computational complexity multiplication have been proposed. For example, Coppersmith-Winograd algorithm has the 

computational complexity on the order of 𝑂(𝑛2.37). Unfortunately such algorithms have a high constant coefficient hidden by the big 𝑂 notation. One technique for matrix multiplication is tiling or blocking. It is a divide and conquer technique, where a matrix is broken down into blocks or tiles. Tiles are first multiplied according to the algorithm, followed by the addition of partial results into the final result; see Figure 8(b) and (6). While this technique does not improve the asymptotic complexity that stays as 𝑂(𝑛^3 ), it allows parallelization of the matrix multiplication algorithm. The comparison of tiled dense matrix multiplication implemented on ControlFlow computer and on DataFlow computer has been done by the authors in [35]. They used a ControlFlow computer with Intel Xeon E5540 CPU with maximal frequency 2.8 GHz and a single MAX4 MAIA DataFlow Engine (DFE) with the frequency of 200 MHz. The algorithm is running on one CPU core and one DFE. The tile size is set to 480 × 480. The authors report the speedup from approximately 16 times for matrices of size 10^3 up to approximately 24 times for matrices of size 10^5. Both presented examples show that DataFlow computers can significantly reduce the time needed for the calculation of results of computationally demanding algorithms. This is especially important in applications where the calculation 

 time is critical. Demanding real-time biofeedback applications, such as the one presented at the beginning of this section, are among such applications. 

#### 8. Conclusion 

 Science and advanced technology offer the possibility of gaining the competitive advantage in sports. Real-time biofeedback systems are one such example. To assure the operation in real time, the technical equipment must be capable of realtime signal acquisition and real-time signal processing with low delay within the biofeedback loop. When the feedback delay in the biofeedback loop is a small portion of human reaction delay, the operation of the biofeedback system is transparent, unnoticeable, to the user, concurrent. Challenges are present in all phases of real-time biomechanical biofeedback systems: at motion capture, at motion data transmission, and at processing. With growing number of biofeedback applications in sport and other areas, their complexity and computational demands will grow as well. Some form of HPC will have to be employed. We suggest that DataFlow computing can be used in many biofeedback applications, when real-time processing is required. 

#### Competing Interests 

 The authors declare that they have no competing interests. 

#### References 

 [1] O. M. Giggins, U. M. Persson, and B. Caulfield, “Biofeedback in rehabilitation,” Journal of NeuroEngineering and Rehabilitation, vol. 10, article 60, 2013. [2] R. Sigrist, G. Rauter, R. Riener, and P. Wolf, “Augmented visual, auditory, haptic, and multimodal feedback in motor learning: a review,” Psychonomic Bulletin & Review, vol. 20, no. 1, pp. 21–53, 2013. [3] A. Baca, P. Dabnichki, M. Heller, and P. Kornfeind, “Ubiquitous computing in sports: a review and analysis,” Journal of Sports Sciences, vol. 27, no. 12, pp. 1335–1346, 2009. [4] B. Lauber and M. Keller, “Improving motor performance: selected aspects of augmented feedback in exercise and health,” European Journal of Sport Science, vol. 14, no. 1, pp. 36–43, 2014. [5] D. G. Liebermann, L. Katz, M. D. Hughes, R. M. Bartlett, J. McClements, and I. M. Franks, “Advances in the application of information technology to sport performance,” Journal of Sports Sciences, vol. 20, no. 10, pp. 755–769, 2002. 


 [6] A. Umek, S. Tomaˇziˇc, and A. Kos, “Wearable training system with real-time biofeedback and gesture user interface,” Personal and Ubiquitous Computing, vol. 19, no. 7, pp. 989–998, 2015. [7] C. C. Y. Poon, B. P. L. Lo, M. R. Yuce, A. Alomainy, and Y. Hao, “Body sensor networks: in the era of big data and beyond,” IEEE Reviews in Biomedical Engineering, vol. 8, pp. 4–16, 2015. [8] Y. Du, F. Hu, L. Wang, and F. Wang, “Framework and challenges for wireless body area networks based on big data,” in Proceedings of the IEEE International Conference on Digital Signal Processing (DSP ’15), pp. 497–501, IEEE, Singapore, July 2015. [9] H. Ghasemzadeh and R. Jafari, “Body sensor networks for baseball swing training: coordination analysis of human movements using motion transcripts,” in Proceedings of the 8th IEEE International Conference on Pervasive Computing and Communications Workshops (PERCOM ’10), pp. 792–795, IEEE, April 2010. [10] C. Wong, Z.-Q. Zhang, B. Lo, and G.-Z. Yang, “Wearable sensing for solid biomechanics: a review,” IEEE Sensors Journal, vol. 15, no. 5, pp. 2747–2760, 2015. [11] V. Milutinovi´c, J. Salom, N. Trifunovic, and R. Giorgi, Guide to DataFlow Supercomputing: Basic Concepts, Case Studies, and a Detailed Example, Springer, Cham, Switzerland, 2015. [12] A. Hurson and V. Milutinovic, DataFlow Processing, vol. 96 of Advances in Computers, Elsevier, New York, NY, USA, 2015. [13] Maxeler Application Gallery, http://appgallery.maxeler.com/#/. [14] A. Kos, S. Tomaˇziˇc, J. Salom, N. Trifunovic, M. Valero, and V. Milutinovic, “New benchmarking methodology and programming model for big data processing,” International Journal of Distributed Sensor Networks, vol. 2015, Article ID 271752, 7 pages, 2015. [15] Human Reaction Time, The Great Soviet Encyclopedia, 3rd edition, 1970–1979. [16] M. Paul, K. Garg, and J. S. Sandhu, “Role of biofeedback in optimizing psychomotor performance in sports,” Asian Journal of Sports Medicine, vol. 3, no. 1, pp. 29–40, 2012. [17] O. Senel and H. Eroglu, “Correlation between reaction time and¨ speed in elite soccer players,” Age, vol. 21, pp. 3–32, 2006. [18] A. Jain, R. Bansal, A. Kumar, and K. Singh, “A comparative study of visual and auditory reaction times on the basis of gender and physical activity levels of medical first year students,” International Journal of Applied and Basic Medical Research, vol. 5, no. 2, p. 124, 2015. [19] M. T. G. Pain and A. Hibbs, “Sprint starts and the minimum auditory reaction time,” Journal of Sports Sciences, vol. 25, no. 1, pp. 79–86, 2007. 

[20] Qualisys Motion Capture System, [http://www.qualisys.com.](http://www.qualisys.com.) 

[21] ST Microelectronics, “MEMS motion sensor: ultra-stable threeaxis digital output gyroscope,” L3G4200D Specifications, ST Microelectronics, Geneva, Switzerland, 2010. [22] J. Guna, G. Jakus, M. Pogaˇcnik, S. Tomaˇziˇc, and J. Sodnik, “An analysis of the precision and reliability of the leap motion sensor and its suitability for static and dynamic tracking,” Sensors, vol. 14, no. 2, pp. 3702–3720, 2014. [23] L. Nilsson, “QTM Real-Time Server Protocol Documentation Version 1.9, Qualisys,” 2011, https://qualisys.github.io/RealTime-Protocol-Documentation/. 

[24] IEEE 802.11 Standards, [http://standards.ieee.org/about/get/802/](http://standards.ieee.org/about/get/802/) 802.11.html. 

[25] H. Ghasemzadeh, S. Ostadabbas, E. Guenterberg, and A. Pantelopoulos, “Wireless medical-embedded systems: a review 

 of signal-processing techniques for classification,” IEEE Sensors Journal, vol. 13, no. 2, pp. 423–437, 2013. [26] S. Stanˇcin and S. Tomaˇziˇc, “Angle estimation of simultaneous orthogonal rotations from 3D gyroscope measurements,” Sensors, vol. 11, no. 9, pp. 8536–8549, 2011. [27] B. Lee James, Y. Ohgi, and D. A. James, “Sensor fusion: let’s enhance the performance of performance enhancement,” Procedia Engineering, vol. 34, pp. 795–800, 2012. [28] M. Sysoev, A. Kos, and M. Pogaˇcnik, “Noninvasive stress recognition considering the current activity,” Personal and Ubiquitous Computing, vol. 19, no. 7, pp. 1045–1052, 2015. [29] A. Yurtman and B. Barshan, “Automated evaluation of physical therapy exercises using multi-template dynamic time warping on wearable sensor signals,” Computer Methods and Programs in Biomedicine, vol. 117, no. 2, pp. 189–207, 2014. [30] K. Peternel, Poga, R. Tavˇcar, and A. Kos, “A presence-based context-aware chronic stress recognition system,” Sensors, vol. 12, no. 11, pp. 15888–15906, 2012. [31] J.-K. Min, B. Choe, and S.-B. Cho, “A selective template matching algorithm for short and intuitive gesture UI of accelerometer-builtin mobile phones,” in Proceedings of the 2nd World Congress on Nature and Biologically Inspired Computing (NaBIC ’10), pp. 660–665, December 2010. [32] M. J. Flynn, O. Mencer, V. Milutinovic et al., “Viewpoint moving from petaflops to petadata,” Communications of the ACM, vol. 56, no. 5, pp. 39–42, 2013. [33] A. V. Aho, J. E. Hopcroft, and J. D. Ullman, The Design and Analysis of Computer Algorithms, 1974, Addison-Wesley, Reading, Mass, USA, 1987. [34] V. Milutinovi´c, J. Salom, N. Trifunovic, and R. Giorgi, “An example application: fourier transform,” in Guide to DataFlow Supercomputing, Computer Communications and Networks, pp. 73–106, Springer, Berlin, Germany, 2015. [35] Maxeler Analytics, Dense Matrix Multiplication, Maxeler Technologies, London, UK, 2015, http://appgallery.maxeler.com/#/. 


### Submit your manuscripts at 

### http://www.hindawi.com 

#### MathematicsHindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

 Journal of Hindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

 Mathematical Problems in Engineering 

 Hindawi P ublishing C orporationhttp://www.hindawi.com 

 Differential Equations 

 International Journal of Volume 201 4 

 Applied M athematics 

 ournal ofJ Hindawi Publishing Corporationhttp://www.hindawi.com Volume 2014 

 Probability and Statistics Hindawi Publishing Corporationhttp://www.hindawi.com Volume 2014 

 Journal of 

 Hindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

 Mathematical Physics 

 Advances in 

#### Complex A nalysis 

Journal of 

 Hindawi Publishing Corporationhttp://www.hindawi.com Volume 201 4 

#### Optimization 

 Journal of 

 Hindawi Publishing Corporationhttp://www.hindawi.com Volume 2014 

#### CombinatoricsHindawi Publishing Corporation 

 http://www.hindawi.com Volume 2014 

International Journal of 

 Hindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

Operations Research 

Advances in 

 Journal of 

 Hindawi Publishing Corporationhttp://www.hindawi.com Volume 2014 

#### Function Spaces 

 Abstract and Applied A nalysis Hindawi Publishing Corporation http://www.hindawi.com Volume 201 4 

 International Journal of Mathematics and Mathematical Sciences 

 Hindawi Publishing Corporationhttp://www.hindawi.com Volume 201 4 

#### The Scientific 

#### World Journal Hindawi Publishing Corporation 

 http://www.hindawi.com Volume 2014 

 Hindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

#### Algebra 

 Discrete D ynamics in Nature and Society Hindawi Publishing Corporation http://www.hindawi.com Volume 201 4 

 Hindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

#### Decision Sciences 

 Advances in 

## Discrete Mathematics 

 Journal of 

 Hindawi Publishing Corporationhttp://www.hindawi.com Volume 201 4 Hindawi P ublishing C orporationhttp://www.hindawi.com Volume 2 0 1 4 

##### Stochastic Analysis 

 International Journal of 


