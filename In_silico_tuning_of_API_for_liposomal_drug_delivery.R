library(tidyverse)
library(dplyr)
library(ggplot2)
library(ggExtra)
library(grid)
library(futile.logger)
library(VennDiagram)
library(RColorBrewer)
library(openxlsx)
library(gridExtra)
library(grid)
library(lattice)
library(gg.gap)

###############################################################################################################################################
# Set method label for selected methods

passive_interactions_from_molmedb <-(passive_12_4_2022)# the latest version of passive interactions from MolMeDB (available on: https://molmedb.upol.cz/stats/show_all )

passive_interactions_from_molmedb$method_label <-  with(passive_interactions_from_molmedb,ifelse(method == "EPAM", "PAMPA",
                                           ifelse(method == "EPAMOL", "PAMPA",
                                                  ifelse(method == "EBAMP", "PAMPA",
                                                         ifelse(method == "ECACO", "CACO-2",
                                                                ifelse(method == "CCM15", "COSMOperm",
                                                                       ifelse(method == "CCM18", "COSMOperm",
                                                                              NA)))))))

selected_methods <- subset(passive_interactions_from_molmedb,passive_interactions_from_molmedb$method_label == "COSMOperm" | passive_interactions_from_molmedb$method_label == "CACO-2" | passive_interactions_from_molmedb$method_label == "PAMPA")
selected_membranes <- subset(selected_methods, selected_methods$membrane != "BBB" &  selected_methods$membrane != "PSKIN" & selected_methods$membrane != "SC mix" & selected_methods$membrane != "CERNS" & selected_methods$membrane != "DPhPC")

# remove data with missing LogPerm and LogP values
permeability_filter<- dplyr::filter(selected_membranes,  !is.na(LogPerm))
permeability_filter<- dplyr::filter(selected_membranes,  !is.na(LogP))

# remove data with inappropriate physical-chemical properties
# increase uniformity of data
permeability_filter <- permeability_filter %>% filter(LogPerm >= -20)
temperature_filter <-permeability_filter %>% filter(temperature == 25)
mass_filter<-temperature_filter %>% filter(MW <= 800)
charge_filter <-mass_filter %>% filter(charge == 0 | is.na(charge))
data <- charge_filter

#number of rows in dataset
nrow(data)
#number of unique molecules (by SMILES) 
length(unique(data$SMILES))

# divide data into 3 sections by their permeability
lower_than_8 <-data[data$LogPerm <= (-8), ]
lower_than_8$type_of_interation <- 'logPerm <= -8'

between_8_and_4<- data[(-8) < (data$LogPerm) & (data$LogPerm) < (-4), ]
between_8_and_4$type_of_interation <-'-8 < logPerm < -4'

highter_than_4 <- data[data$LogPerm >= (-4), ]
highter_than_4$type_of_interation<-'logPerm >= -4'

data <-unique(rbind(lower_than_8,between_8_and_4, highter_than_4))

# number of molecules in the sections
length(unique(lower_than_8[["SMILES"]]))
length(unique(between_8_and_4[["SMILES"]]))
length(unique(highter_than_4[["SMILES"]]))
#####################################################################################################################################################
# group by and caluculations of mean values of permeability
COSMO <- (subset(data, method_label == "COSMOperm"))
length(unique(COSMO$SMILES))
CACO <- (subset(data, method_label == "CACO-2"))
length(unique(CACO$SMILES))
PAMPA <- (subset(data, method_label == "PAMPA"))
length(unique(PAMPA$SMILES))

COSMO_mean <- COSMO %>%    
  group_by(SMILES) %>%
  summarise(LOgPerm_mean = mean(LogPerm))
PAMPA_mean <- PAMPA %>%   
  group_by(SMILES) %>%
  summarise(LOgPerm_mean = mean(LogPerm))
CACO_mean <- CACO %>%   
  group_by(SMILES) %>%
  summarise(LOgPerm_mean = mean(LogPerm))

# scatter plots with lines
COSMO_and_CACO <- merge(COSMO_mean,CACO_mean,by.x ="SMILES", by.y = "SMILES") 
COSMO_and_PAMPA <- merge(COSMO_mean,PAMPA_mean,by="SMILES")
CACO_and_PAMPA <- merge(CACO_mean,PAMPA_mean,by="SMILES")

# Add 2 extra points "x" into dataset because of appearance scatter plot
COSMO_and_CACO[nrow(COSMO_and_CACO) + 1,] <- c("x", -8.7, -8.7)
COSMO_and_CACO[nrow(COSMO_and_CACO) + 1,] <- c("x", 0.7, 0.7)
COSMO_and_PAMPA[nrow(COSMO_and_PAMPA) + 1,] <- c("x", -8.7, -8.7)
COSMO_and_PAMPA[nrow(COSMO_and_PAMPA) + 1,] <- c("x", 0.7, 0.7)
CACO_and_PAMPA[nrow(CACO_and_PAMPA) + 1,] <- c("x", -8.7, -8.7)
CACO_and_PAMPA[nrow(CACO_and_PAMPA) + 1,] <- c("x", 0.7, 0.7)

# Color setting by data
# "x" point are always white
COSMO_and_CACO$color_of_point <-  with(COSMO_and_CACO,ifelse(LOgPerm_mean.x == -8.7 | LOgPerm_mean.x == 0.7, "white", "navyblue"))
COSMO_and_PAMPA$color_of_point <-  with(COSMO_and_PAMPA,ifelse(LOgPerm_mean.x == -8.7 | LOgPerm_mean.x == 0.7, "white", "firebrick3"))                                                                                                            
CACO_and_PAMPA$color_of_point <-  with(CACO_and_PAMPA,ifelse(LOgPerm_mean.x == -8.7 | LOgPerm_mean.x == 0.7, "white", "seagreen")) 

# set data type from character to double
COSMO_and_CACO$LOgPerm_mean.x <- as.double(COSMO_and_CACO$LOgPerm_mean.x)
COSMO_and_CACO$LOgPerm_mean.y <- as.double(COSMO_and_CACO$LOgPerm_mean.y)
COSMO_and_PAMPA$LOgPerm_mean.x <- as.double(COSMO_and_PAMPA$LOgPerm_mean.x)
COSMO_and_PAMPA$LOgPerm_mean.y <- as.double(COSMO_and_PAMPA$LOgPerm_mean.y)
CACO_and_PAMPA$LOgPerm_mean.x <- as.double(CACO_and_PAMPA$LOgPerm_mean.x)
CACO_and_PAMPA$LOgPerm_mean.y <- as.double(CACO_and_PAMPA$LOgPerm_mean.y)


# Add  dashed lines
# y = x+1
COSMO_and_CACO$x_1_summ <- COSMO_and_CACO$LOgPerm_mean.x + 1
COSMO_and_PAMPA$x_1_summ <- COSMO_and_PAMPA$LOgPerm_mean.x + 1
CACO_and_PAMPA$x_1_summ <- CACO_and_PAMPA$LOgPerm_mean.x + 1
# y = x-1
COSMO_and_CACO$x_1_diff<- COSMO_and_CACO$LOgPerm_mean.x - 1
COSMO_and_PAMPA$x_1_diff <- COSMO_and_PAMPA$LOgPerm_mean.x - 1
CACO_and_PAMPA$x_1_diff <- CACO_and_PAMPA$LOgPerm_mean.x - 1

# Scatter plots
COSMO_and_CACO_plot <-ggplot(COSMO_and_CACO, aes(LOgPerm_mean.x, LOgPerm_mean.y,color = color_of_point)) +
  geom_point()+coord_cartesian(xlim = c(-9, 0.8), ylim = c(-9, 0.8)) +
  #stat_smooth(method = lm, se = FALSE, linetype = "dashed")+
  labs(title = "A")+
  theme(text = element_text(size = 30))+
  theme_bw()+
  geom_point()+
  scale_color_identity()+
  labs(y = "LogPerm CACO-2", x = "LogPerm COSMOperm")+
  geom_line(aes(x = LOgPerm_mean.x, y = x_1_summ), linetype = "dashed", colour = "black")+
  geom_line(aes(x = LOgPerm_mean.x, y = x_1_diff), linetype = "dashed", colour = "black")+
  geom_line(aes(x = LOgPerm_mean.x, y = LOgPerm_mean.x, colour = "black"))
COSMO_and_CACO_plot

COSMO_and_PAMPA_plot<-ggplot(COSMO_and_PAMPA, aes(LOgPerm_mean.x, LOgPerm_mean.y, color = color_of_point)) +
  geom_point()+coord_cartesian(xlim = c(-9, 0.8), ylim = c(-9, 0.8))+
  #stat_smooth(method = lm, se = FALSE, linetype = "dashed")+
  labs(title = "B")+
  theme(text = element_text(size = 30))+
  geom_point()+
  theme_bw()+
  scale_color_identity()+
  labs(y = "LogPerm PAMPA", x = "LogPerm COSMOperm")+
  geom_line(aes(x = LOgPerm_mean.x, y = x_1_summ ),linetype = "dashed", colour = "black")+
  geom_line(aes(x = LOgPerm_mean.x, y = x_1_diff),linetype = "dashed", colour = "black")+
  geom_line(aes(x = LOgPerm_mean.x, y = LOgPerm_mean.x, colour = "black"))
COSMO_and_PAMPA_plot

CACO_and_PAMPA_plot <- ggplot(CACO_and_PAMPA, aes(LOgPerm_mean.x, LOgPerm_mean.y, color = color_of_point)) +
  geom_point()+coord_cartesian(xlim = c(-9, 0.8), ylim = c(-9, 0.8)) +
  #stat_smooth(method = lm, se = FALSE, linetype = "dashed")+
  labs(title = "C")+
  theme(text = element_text(size = 30))+
  geom_point()+
  scale_color_identity()+
  theme_bw()+
  labs(y = "LogPerm PAMPA", x = "LogPerm CACO-2")+
  geom_line(aes(x = LOgPerm_mean.x, y = x_1_summ), linetype = "dashed", colour = "black")+
  geom_line(aes(x = LOgPerm_mean.x, y = x_1_diff), linetype = "dashed", colour = "black")+
  geom_line(aes(x = LOgPerm_mean.x, y = LOgPerm_mean.x,colour = "black"))
CACO_and_PAMPA_plot

grid.arrange(COSMO_and_CACO_plot, COSMO_and_PAMPA_plot,CACO_and_PAMPA_plot, nrow=1)# JEDEN Z TĚCHTO SMAZAT!!!!!
grid.arrange(arrangeGrob(COSMO_and_CACO_plot, COSMO_and_PAMPA_plot,CACO_and_PAMPA_plot, ncol=3, nrow=1),heights=c(10,10,1), widths=c(10,1,1))

##################################################################################################################################
# Histograms

histogram_PAMPA<-ggplot(PAMPA, aes(x=LogPerm)) +
  theme_bw()+ geom_histogram(alpha=0.2, binwidth=0.5, color="dodgerblue2", fill="dodgerblue2")+ labs(title = "A")+
  theme(text = element_text(size = 15))+
  scale_x_continuous(lim = c(-15, 3))
histogram_PAMPA_with_gap <-gg.gap(plot=histogram_PAMPA,segments=c(5,10), tick_width = 5,ylim=c(0,90))
histogram_PAMPA_with_gap 

histogram_COSMO<-ggplot(COSMO, aes(x=LogPerm)) +
  theme_bw()+geom_histogram(alpha=0.2, position="identity", binwidth=0.5, color="mediumseagreen", fill="mediumseagreen")+labs(title = "B")+
  theme(text = element_text(size = 15))+
  scale_x_continuous(lim = c(-15, 3))
histogram_COSMO_with_gap <-gg.gap(plot=histogram_COSMO,segments=c(5,10), tick_width = 5,ylim=c(0,90))
histogram_COSMO_with_gap

histogram_CACO<-ggplot(CACO, aes(x=LogPerm)) +
  theme_bw()+geom_histogram(alpha=0.2, position="identity", binwidth=0.5, color="lightpink3", fill="lightpink3")+labs(title = "C")+
  theme(text = element_text(size = 15))+
  scale_x_continuous(lim = c(-15, 3))
histogram_CACO_with_gap <-gg.gap(plot=histogram_CACO,segments=c(5,10), tick_width =5,ylim=c(0,90))
histogram_CACO_with_gap

grid.arrange(histogram_PAMPA_with_gap,histogram_COSMO_with_gap,histogram_CACO_with_gap, nrow=1)

###########################################################################################################################################




